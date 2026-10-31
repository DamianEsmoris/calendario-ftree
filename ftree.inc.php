<?php
const INT_SIZE = 8;
const CHAR_SIZE = 8;
const NODE_VALUE_LENGTH = 50;

class Node {
    public const PTR_SIZE = INT_SIZE;
    public const KEY_SIZE = INT_SIZE;
    public const VALUE_SIZE = CHAR_SIZE*NODE_VALUE_LENGTH;
    public const ENCODED_SIZE = Node::KEY_SIZE + Node::PTR_SIZE*2 + Node::VALUE_SIZE;

    public int $left;
    public int $right;

    public function __construct(public int $key, public string $value) {
        $this->left = 0;
        $this->right = 0;
    }

    public function encode(): string {
        return implode('', [
            self::intEncode($this->left),
            self::intEncode($this->key),
            self::intEncode($this->right),
            self::strEncode($this->value)
        ]);
    }

    public static function Decode(string $encodedStr): Node {
        $left =  self::intDecode(substr($encodedStr, 0, 8));
        $key =  self::intDecode(substr($encodedStr, 8, 16));
        $right =  self::intDecode(substr($encodedStr, 16, 24));
        $value =  self::strDecode(substr($encodedStr, 24, Node::ENCODED_SIZE));

        $n = new Node($key, $value);
        $n->left = $left;
        $n->right = $right;
        return $n;
    }

    public static function encodePtr(int $ptr): string {
        return self::intEncode($ptr);
    }

    public static function encodeValueField(string $value): string {
        return self::strEncode($value);
    }

    // little-endian 4-byte uint32
    private static function intEncode(int $x): string {
        return bin2hex(pack('V', $x));
    }

    private static function intDecode(string $encoded): int {
        return unpack('V', hex2bin(substr($encoded, 0, 8)))[1];
    }

    private static function strEncode(string $str): string {
        $out = '';
        for ($i = 0; $i < NODE_VALUE_LENGTH; $i++) {
            $out .= self::intEncode($i < strlen($str) ? ord($str[$i]) : 0);
        }
        return $out;
    }

    private static function strDecode(string $encStr): string {
        $result = '';
        for ($i = 0; $i < strlen($encStr); $i += 8) {
            $charCode = self::intDecode(substr($encStr, $i, 8));
            if ($charCode === 0) continue;
            $result .= chr($charCode);
        }
        return $result;
    }
}

class FTree {
    /** @var resource PHP has no usable `resource` type declaration, so this stays untyped */
    private $f;
    public function __construct(protected string $filePath) {
        $f = fopen($filePath, 'c+');
        if (!$f) {
            throw new Exception('Access denied (probably)');
        }
        $this->f = $f;
    }

    public function __destruct() {
        if (!$this->f) {
            return;
        }
        fclose($this->f);
    }

    private function getNextPtr(): int {
        clearstatcache();
        return fstat($this->f)['size']+1;
    }

    private function isEmpty(): bool {
        return $this->getNextPtr() == 1;
    }

    public function append(int $key, string $value): void {
        if ($this->isEmpty()) {
            $this->pushNode(new Node($key, $value));
            return;
        }

        $parentPtr = 1;
        $parent = $this->getNodeByPtr($parentPtr);

        while (
            ($key < $parent->key && $parent->left) ||
            ($key >  $parent->key && $parent->right)
        ) {
            $parentPtr = $key <= $parent->key ? $parent->left : $parent->right;
            $parent = $this->getNodeByPtr($parentPtr);
        }

        // Node's key already exists.
        // Change the value only.
        if ($key == $parent->key) {
            $offset = $parentPtr - 1 + Node::KEY_SIZE + Node::PTR_SIZE*2;
            fseek($this->f, $offset, SEEK_SET);
            fwrite($this->f, Node::encodeValueField($value));
            return;
        }

        // Add a new node and update parent's ptrs.
        $ptr = $this->getNextPtr();
        $ptrEnc = Node::encodePtr($ptr);
        $this->pushNode(new Node($key, $value));
        $offset = $parentPtr - 1 + (
            $key <= $parent->key
            ? 0
            : Node::PTR_SIZE + Node::KEY_SIZE
        );
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $ptrEnc);
    }

    private function getNodeByPtr(int $ptr): ?Node {
        if ($ptr < 1) {
            return null;
        }
        $ptr--;
        fseek($this->f, $ptr, SEEK_SET);
        $nodeEncoded = fread($this->f, Node::ENCODED_SIZE);
        return Node::Decode($nodeEncoded);
    }

    private function pushNode(Node $node): void {
        fseek($this->f, 0, SEEK_END);
        fwrite($this->f, $node->encode(), $node::ENCODED_SIZE);
    }

    public function update(int $key, string $value): bool {
        if ($this->find($key) === null) {
            return false;
        }
        $this->append($key, $value);
        return true;
    }

    public function find(int $key): ?string {
        if ($this->isEmpty()) {
            return null;
        }

        $parentPtr = 1;
        $parent = $this->getNodeByPtr($parentPtr);

        while (
            $parent->key !== $key && (
                ($key <= $parent->key && $parent->left) ||
                ($key >  $parent->key && $parent->right)
            )
        ) {
            $parentPtr = $key <= $parent->key ? $parent->left : $parent->right;
            $parent = $this->getNodeByPtr($parentPtr);
        }
        return $parent->key === $key ? $parent->value : null;
    }

    public function inOrder(): array {
        $arr = [];
        foreach ($this->_inOrder($this->getNodeByPtr(1)) as $node) {
            $arr[$node->key] = $node->value;
        }
        return $arr;
    }

    /** @return Node[] */
    private function _inOrder(?Node $root, array $arr = []): array {
        if (!$root) {
            return $arr;
        }

        $arr = $this->_inOrder($this->getNodeByPtr($root->left), $arr);
        array_push($arr, $root);
        $arr = $this->_inOrder($this->getNodeByPtr($root->right), $arr);
        return $arr;
    }

    public function findRange(int $fromKey, int $toKey): array {
        $arr = [];
        foreach ($this->_findRange($fromKey, $toKey, $this->getNodeByPtr(1)) as $node) {
            $arr[$node->key] = $node->value;
        }
        return $arr;
    }

    /** @return Node[] */
    private function _findRange(int $fromKey, int $toKey, ?Node $root, array $arr = []): array {
        if (!$root) {
            return $arr;
        }

        if ($fromKey <= $root->key) {
            $arr = $this->_findRange(
                $fromKey,
                $toKey,
                $this->getNodeByPtr($root->left),
                $arr
            );
        }

        if ($root->key >= $fromKey && $root->key <= $toKey) {
            array_push($arr, $root);
        }

        if ($toKey >= $root->key) {
            $arr = $this->_findRange(
                $fromKey,
                $toKey,
                $this->getNodeByPtr($root->right),
                $arr
            );
        }
        return $arr;
    }
}
