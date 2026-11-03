<?php
const INT_SIZE = 8;
const CHAR_SIZE = 8;
const NODE_VALUE_LENGTH = 50;

class Node
{
    public const PTR_SIZE = INT_SIZE;
    public const KEY_SIZE = INT_SIZE;
    public const HEIGHT_SIZE = INT_SIZE;
    public const ENCODED_HEADER_SIZE = self::KEY_SIZE + self::PTR_SIZE * 2 + self::HEIGHT_SIZE;

    public int $left;
    public int $right;
    public int $height;
    public int $ptr;

    public function __construct(public int $key, public string $value)
    {
        $this->left = 0;
        $this->right = 0;
        $this->height = 1;
    }

    public function encode(int $valueSize): string
    {
        return implode('', [
            self::intEncode($this->left),
            self::intEncode($this->key),
            self::intEncode($this->right),
            self::intEncode($this->height),
            self::strEncode($this->value, $valueSize)
        ]);
    }

    public static function Decode(string $encodedStr, int $valueSize): self
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
        $t += $valueSize;
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
    public static function Balance(?Node $left, ?Node $right): int
    {
        return self::Height($left) - self::Height($right);
    }

    public static function encodePtr(int $ptr): string
    {
        return self::intEncode($ptr);
    }

    public static function decodePtr(string $ptrEnc): string
    {
        return self::intDecode($ptrEnc);
    }

    public static function decodevalueSize(string $valueSizeEnc): string
    {
        return self::intDecode($valueSizeEnc);
    }

    public static function encodeValueField(string $value, int $valueSize): string
    {
        return self::strEncode($value, $valueSize);
    }

    public static function encodeHeightField(int $height): string
    {
        return self::intEncode($height);
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

    private static function strEncode(string $str, int $valueSize): string
    {
        $charCount = $valueSize / CHAR_SIZE;
        $out = '';
        for ($i = 0; $i < $charCount; $i++) {
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
    private $rootPtr;
    private $encodedTotalSize;
    private $valueSize;
    private $globalFileOffset = Node::PTR_SIZE + INT_SIZE;

    public function __construct(protected string $filePath, int $valueLength = 50)
    {
        $f = fopen($filePath, 'c+');
        if (!$f) {
            throw new Exception('Access denied (probably)');
        }
        $this->f = $f;

        // Get root ptr if preset
        if ($this->isEmpty($filePath)) {
            $this->setRootPtr(0);
            $this->setValueSize($valueLength * CHAR_SIZE);
        } else {
            fseek($this->f, 0, SEEK_SET);
            $ptrEnc = fread($this->f, Node::PTR_SIZE);

            fseek($this->f, Node::PTR_SIZE, SEEK_SET);
            $encValueSize = fread($this->f, INT_SIZE);

            $this->setRootPtr(Node::decodePtr($ptrEnc));
            $this->valueSize = Node::decodevalueSize($encValueSize);
        }
        $this->encodedTotalSize = NODE::ENCODED_HEADER_SIZE + $this->valueSize;
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
        clearstatcache();
        return fstat($this->f)['size'] <= $this->globalFileOffset;
    }

    public function append(int $key, string $value): void
    {
        if ($this->isEmpty()) {
            $ptr = $this->getNextPtr();
            $this->pushNode(new Node($key, $value));
            $this->setRootPtr($ptr);
            return;
        }
        $newRoot = $this->_append($key, $value, $this->rootPtr);
        if ($newRoot !== $this->rootPtr) {
            $this->setRootPtr($newRoot);
        }
    }

    public function _append(int $key, string $value, int $parentPtr): int
    {
        $parent = $this->getNodeByPtr($parentPtr);
        if (!$parent) {
            return $parentPtr;
        }
        if ($key == $parent->key) {
            throw new Exception('Key ' . $key . ' already exists. use update to replace the content');
        }

        $child = $key <= $parent->key ? $parent->left : $parent->right;

        if (!$child) {
            $ptr = $this->getNextPtr();
            $this->pushNode(new Node($key, $value));
            if ($key <= $parent->key) {
                $this->setNodeLeftPtr($parent, $ptr);
            }
            else {
                $this->setNodeRightPtr($parent, $ptr);
            }
            // Keep looking for space
        } else {
            $newChildRoot = $this->_append($key, $value, $child);
            if ($newChildRoot !== $child) {
                if ($key <= $parent->key) {
                    $this->setNodeLeftPtr($parent, $newChildRoot);
                } else {
                    $this->setNodeRightPtr($parent, $newChildRoot);
                }
            }
        }

        $this->recomputeHeight($parent);

        $leftChild = $this->getNodeByPtr($parent->left);
        $rightChild = $this->getNodeByPtr($parent->right);

        $parentBalance = Node::Balance($leftChild, $rightChild);
        $leftChildBalance = $leftChild
            ? Node::Balance($this->getNodeByPtr($leftChild->left), $this->getNodeByPtr($leftChild->right))
            : 0;
        $rightChildBalance = $rightChild
            ? Node::Balance($this->getNodeByPtr($rightChild->left), $this->getNodeByPtr($rightChild->right))
            : 0;

        // LL
        if ($parentBalance > 1 && $leftChildBalance >= 0) {
            return $this->antiClockWiseRot($parent);
        }

        // LR
        if ($parentBalance > 1 && $leftChildBalance < 0) {
            $newLeftPtr = $this->clockWiseRot($leftChild);
            $this->setNodeLeftPtr($parent, $newLeftPtr);
            return $this->antiClockWiseRot($parent);
        }

        // RR
        if ($parentBalance < -1 && $rightChildBalance <= 0) {
            return $this->clockWiseRot($parent);
        }

        // RL
        if ($parentBalance < -1 && $rightChildBalance > 0) {
            $newRightPtr = $this->antiClockWiseRot($rightChild);
            $this->setNodeRightPtr($parent, $newRightPtr);
            return $this->clockWiseRot($parent);
        }

        return $parent->ptr;
    }

    private function getNodeByPtr(int $ptr): ?Node
    {
        if ($ptr < 1) {
            return null;
        }
        $ptr--;
        fseek($this->f, $ptr, SEEK_SET);
        $nodeEncoded = fread($this->f, $this->encodedTotalSize);
        if ($nodeEncoded === false || strlen($nodeEncoded) < $this->encodedTotalSize) {
            return null;
        }
        $node = Node::Decode($nodeEncoded, $this->valueSize);
        $node->ptr = $ptr+1;
        return $node;
    }

    private function pushNode(Node $node): void
    {
        fseek($this->f, 0, SEEK_END);
        fwrite($this->f, $node->encode($this->valueSize), $this->encodedTotalSize);
    }

    public function update(int $key, string $value): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $nodePtr = $this->rootPtr;
        $node = $this->getNodeByPtr($nodePtr);

        while ($node && $node->key !== $key) {
            $nodePtr = $key <= $node->key ? $node->left : $node->right;
            $node = $this->getNodeByPtr($nodePtr);
        }

        if (!$node) {
            return false;
        }

        $this->setNodeValue($node, $value);
        return true;
    }

    public function appendOrUpdate(int $key, string $value): bool
    {
        if ($this->find($key) === null) {
            $this->append($key, $value);
            return true;
        }
        return $this->update($key, $value);
    }

    public function height(): int
    {
        return Node::Height($this->getRootNode());
    }

    public function find(int $key): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $parentPtr = $this->rootPtr;
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
        foreach ($this->_inOrder($this->getRootNode()) as $node) {
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
        foreach ($this->_findRange($fromKey, $toKey, $this->getRootNode()) as $node) {
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

    private function clockWiseRot(Node $node): int
    {
        $pivot = $this->getNodeByPtr($node->right);
        $pivotLeftPtr = $pivot->left;

        $this->setNodeRightPtr($node, $pivotLeftPtr);
        $this->setNodeLeftPtr($pivot, $node->ptr);

        $this->recomputeHeight($node);
        $this->recomputeHeight($pivot);

        return $pivot->ptr;
    }

    private function antiClockWiseRot(Node $node): int
    {
        $pivot = $this->getNodeByPtr($node->left);
        $pivotRightPtr = $pivot->right;

        $this->setNodeLeftPtr($node, $pivotRightPtr);
        $this->setNodeRightPtr($pivot, $node->ptr);

        $this->recomputeHeight($node);
        $this->recomputeHeight($pivot);

        return $pivot->ptr;
    }

    private function recomputeHeight(Node $node): void
    {
        $left = $this->getNodeByPtr($node->left);
        $right = $this->getNodeByPtr($node->right);
        $this->setNodeHeight($node, 1 + max(Node::Height($left), Node::Height($right)));
    }

    private function setNodeRightPtr(Node $node, int $value) {
        $offset = $node->ptr - 1 + Node::PTR_SIZE + Node::KEY_SIZE;
        $ptrEnc = Node::encodePtr($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $ptrEnc);
        $node->right = $value;
    }

    private function setNodeLeftPtr(Node $node, int $value) {
        $offset = $node->ptr - 1 + 0;
        $ptrEnc = Node::encodePtr($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $ptrEnc);
        $node->left = $value;
    }

    private function setNodeHeight(Node $node, int $value)
    {
        $offset = $node->ptr - 1 + Node::PTR_SIZE * 2 + Node::KEY_SIZE;
        $heightEnc = Node::encodeHeightField($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $heightEnc);
        $node->height = $value;
    }

    private function setNodeValue(Node $node, string $value)
    {
        $offset = $node->ptr - 1 + Node::KEY_SIZE + Node::PTR_SIZE * 2 + Node::HEIGHT_SIZE;
        $valueEnc = Node::encodeValueField($value, $this->valueSize);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $valueEnc);
        $node->value = $value;
        return true;
    }

    private function setRootPtr(int $value)
    {
        $this->rootPtr = $value;
        $ptrEnc = Node::encodePtr($value);
        fseek($this->f, 0, SEEK_SET);
        fwrite($this->f, $ptrEnc);
    }

    private function setValueSize(int $value): void
    {
        $this->valueSize = $value;
        $sizeEnc = Node::encodePtr($value);
        fseek($this->f, Node::PTR_SIZE, SEEK_SET);
        fwrite($this->f, $sizeEnc);
    }

    private function getRootNode(): Node | null
    {
        return $this->getNodeByPtr($this->rootPtr);
    }
};

