<?php
const INT_SIZE = 8;
const CHAR_SIZE = 8;
const NODE_VALUE_LENGTH = 50;

class Node
{
    public const PTR_SIZE = INT_SIZE;
    public const KEY_SIZE = INT_SIZE;
    public const HEIGHT_SIZE = INT_SIZE;
    public const VALUE_SIZE = CHAR_SIZE * NODE_VALUE_LENGTH;
    public const ENCODED_SIZE = self::KEY_SIZE + self::PTR_SIZE * 2 + self::HEIGHT_SIZE + self::VALUE_SIZE;

    public int $left;
    public int $right;
    public int $height;

    public function __construct(public int $key, public string $value)
    {
        $this->left = 0;
        $this->right = 0;
        $this->height = 0;
    }

    public function encode(): string
    {
        return implode('', [
            self::intEncode($this->left),
            self::intEncode($this->key),
            self::intEncode($this->right),
            self::intEncode($this->height),
            self::strEncode($this->value)
        ]);
    }

    public static function Decode(string $encodedStr): self
    {
        $f = 0;
        $t = self::PTR_SIZE;
        $left = self::intDecode(substr($encodedStr, $f, $t));

        $f = $t;
        $t += self::KEY_SIZE;
        $key = self::intDecode(substr($encodedStr, $f, $t));

        $f = $t;
        $t += self::PTR_SIZE;
        $right = self::intDecode(substr($encodedStr, $f, $t));

        $f = $t;
        $t += self::HEIGHT_SIZE;
        $height = self::intDecode(substr($encodedStr, $f, $t));

        $f = $t;
        $t += self::ENCODED_SIZE;
        $value = self::strDecode(substr($encodedStr, $f, $t));

        $n = new Node($key, $value);
        $n->left = $left;
        $n->right = $right;
        $n->height = $height;
        return $n;
    }

    public static function Height(?Node $node): int
    {
        if (!$node) {
            return 0;
        }
        return $node->height;
    }

    public static function encodePtr(int $ptr): string
    {
        return self::intEncode($ptr);
    }

    public static function encodeValueField(string $value): string
    {
        return self::strEncode($value);
    }

    // little-endian 4-byte uint32
    private static function intEncode(int $x): string
    {
        return bin2hex(pack('V', $x));
    }

    private static function intDecode(string $encoded): int
    {
        return unpack('V', hex2bin(substr($encoded, 0, 8)))[1];
    }

    private static function strEncode(string $str): string
    {
        $out = '';
        for ($i = 0; $i < NODE_VALUE_LENGTH; $i++) {
            $out .= self::intEncode($i < strlen($str) ? ord($str[$i]) : 0);
        }
        return $out;
    }

    private static function strDecode(string $encStr): string
    {
        $result = '';
        for ($i = 0; $i < strlen($encStr); $i += 8) {
            $charCode = self::intDecode(substr($encStr, $i, 8));
            if ($charCode === 0) continue;
            $result .= chr($charCode);
        }
        return $result;
    }
}

class FTree
{
    /** @var resource PHP has no usable `resource` type declaration, so this stays untyped */
    private $f;
    public function __construct(protected string $filePath)
    {
        $f = fopen($filePath, 'c+');
        if (!$f) {
            throw new Exception('Access denied (probably)');
        }
        $this->f = $f;
    }

    public function __destruct()
    {
        if (!$this->f) {
            return;
        }
        fclose($this->f);
    }

    private function getNextPtr(): int
    {
        clearstatcache();
        return fstat($this->f)['size'] + 1;
    }

    private function isEmpty(): bool
    {
        return $this->getNextPtr() == 1;
    }

    public function append(int $key, string $value): void
    {
        if ($this->isEmpty()) {
            $this->pushNode(new Node($key, $value));
            return;
        }
        $rootPtr = 1;
        $this->_append($key, $value, $rootPtr);
    }

    public function _append(int $key, string $value, int $parentPtr): void
    {
        $parent = $this->getNodeByPtr($parentPtr);
        if (!$parent) {
            return;
        }
        if ($key == $parent->key) {
            throw new Exception('Key ' . $key . ' already exists. use update to replace the content');
        }

        $child = $key <= $parent->key ? $parent->left : $parent->right;

        if (!$child) {
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
        // Keep looking for space
        } else {
            $this->_append(
                $key,
                $value,
                $child
            );
        }

        $leftChild = $this->getNodeByPtr($parent->left);
        $rightChild = $this->getNodeByPtr($parent->right);
        $parent->height = 1 + max(
            Node::Height($rightChild),
            Node::Height($leftChild)
        );

    }

    private function getNodeByPtr(int $ptr): ?Node
    {
        if ($ptr < 1) {
            return null;
        }
        $ptr--;
        fseek($this->f, $ptr, SEEK_SET);
        $nodeEncoded = fread($this->f, Node::ENCODED_SIZE);
        if ($nodeEncoded === false || strlen($nodeEncoded) < Node::ENCODED_SIZE) {
            return null;
        }
        return Node::Decode($nodeEncoded);
    }

    private function pushNode(Node $node): void
    {
        fseek($this->f, 0, SEEK_END);
        fwrite($this->f, $node->encode(), $node::ENCODED_SIZE);
    }

    public function update(int $key, string $value): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $rootPtr = 1;
        $root = $this->getNodeByPtr($rootPtr);

        while ($root && $root->key !== $key) {
            $rootPtr = $key <= $root->key ? $root->left : $root->right;
            $root = $this->getNodeByPtr($rootPtr);
        }

        $offset = $rootPtr - 1 + Node::KEY_SIZE + Node::PTR_SIZE * 2 + NODE::HEIGHT_SIZE;
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, Node::encodeValueField($value));
        return true;
    }

    public function appendOrUpdate(int $key, string $value) {
        if (!$this->find($key)) {
            $this->append($key, $value);
        }
        $this->update($key, $value);
    }

    public function find(int $key): ?string
    {
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

    public function inOrder(): array
    {
        $arr = [];
        foreach ($this->_inOrder($this->getNodeByPtr(1)) as $node) {
            $arr[$node->key] = $node->value;
        }
        return $arr;
    }

    /** @return Node[] */
    private function _inOrder(?Node $root, array $arr = []): array
    {
        if (!$root) {
            return $arr;
        }

        $arr = $this->_inOrder($this->getNodeByPtr($root->left), $arr);
        array_push($arr, $root);
        $arr = $this->_inOrder($this->getNodeByPtr($root->right), $arr);
        return $arr;
    }

    public function findRange(int $fromKey, int $toKey): array
    {
        $arr = [];
        foreach ($this->_findRange($fromKey, $toKey, $this->getNodeByPtr(1)) as $node) {
            $arr[$node->key] = $node->value;
        }
        return $arr;
    }

    /** @return Node[] */
    private function _findRange(int $fromKey, int $toKey, ?Node $root, array $arr = []): array
    {
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
