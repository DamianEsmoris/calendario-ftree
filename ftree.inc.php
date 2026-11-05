<?php
const INT_SIZE = 8;
const CHAR_SIZE = 8;
const NODE_VALUE_LENGTH = 50;

class NodeHeader
{
    public const PTR_SIZE = INT_SIZE;
    public const KEY_SIZE = INT_SIZE;
    public const HEIGHT_SIZE = INT_SIZE;
    public const ENCODED_HEADER_SIZE = self::KEY_SIZE + self::PTR_SIZE * 2 + self::HEIGHT_SIZE;

    public int $left;
    public int $right;
    public int $height;
    public int $ptr;

    public function __construct(public int $key)
    {
        $this->left = 0;
        $this->right = 0;
        $this->height = 1;
    }

    public static function decodeHeader(string $encodedStr): self
    {
        [$left, $key, $right, $height] = self::decodeHeaderFields($encodedStr);

        $node = new self($key);
        $node->left = $left;
        $node->right = $right;
        $node->height = $height;
        return $node;
    }

    protected static function decodeHeaderFields(string $encodedStr): array
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

        return [$left, $key, $right, $height];
    }

    public static function height(?NodeHeader $node): int
    {
        if (!$node) {
            return 0;
        }
        return $node->height;
    }

    public static function balance(?NodeHeader $left, ?NodeHeader $right): int
    {
        return self::height($left) - self::height($right);
    }

    public static function encodePtr(int $ptr): string
    {
        return self::intEncode($ptr);
    }

    public static function decodePtr(string $ptrEnc): string
    {
        return self::intDecode($ptrEnc);
    }

    public static function decodeValueSize(string $valueSizeEnc): string
    {
        return self::intDecode($valueSizeEnc);
    }

    public static function encodeHeightField(int $height): string
    {
        return self::intEncode($height);
    }

    // little-endian 4-byte uint32
    protected static function intEncode(int $x): string
    {
        return bin2hex(pack('V', $x));
    }

    protected static function intDecode(string $encoded): int
    {
        return unpack('V', hex2bin(substr($encoded, 0, 8)))[1];
    }
}

// A NodeHeader plus its value - the full on-disk record.
class Node extends NodeHeader
{
    public function __construct(int $key, public string $value)
    {
        parent::__construct($key);
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

    public static function decodeFullNode(string $encodedStr, int $valueSize): self
    {
        [$left, $key, $right, $height] = self::decodeHeaderFields($encodedStr);
        $value = self::strDecode(substr($encodedStr, self::ENCODED_HEADER_SIZE, $valueSize));

        $node = new self($key, $value);
        $node->left = $left;
        $node->right = $right;
        $node->height = $height;
        return $node;
    }

    public static function encodeValueField(string $value, int $valueSize): string
    {
        return self::strEncode($value, $valueSize);
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
    private $globalFileOffset = NodeHeader::PTR_SIZE + INT_SIZE;
    /** @var array<int, NodeHeader> */
    private $headersCache;
    private $cacheSize;

    public function __construct(protected string $filePath, int $valueLength = 50, public int $cacheLimit = 100_000)
    {
        $f = fopen($filePath, 'c+');
        if (!$f) {
            throw new Exception('Access denied (probably)');
        }
        $this->f = $f;

        $this->headersCache = [];
        $this->cacheSize = 0;

        // Get root ptr if preset
        if ($this->isEmpty($filePath)) {
            $this->setRootPtr(0);
            $this->setValueSize($valueLength * CHAR_SIZE);
        } else {
            fseek($this->f, 0, SEEK_SET);
            $ptrEnc = fread($this->f, NodeHeader::PTR_SIZE);

            fseek($this->f, NodeHeader::PTR_SIZE, SEEK_SET);
            $encValueSize = fread($this->f, INT_SIZE);

            $this->setRootPtr(NodeHeader::decodePtr($ptrEnc));
            $this->valueSize = NodeHeader::decodeValueSize($encValueSize);
        }
        $this->encodedTotalSize = NodeHeader::ENCODED_HEADER_SIZE + $this->valueSize;
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
        $parent = $this->getNodeHeaderByPtr($parentPtr);
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
            } else {
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

        $leftChild = $this->getNodeHeaderByPtr($parent->left);
        $rightChild = $this->getNodeHeaderByPtr($parent->right);

        $parentBalance = NodeHeader::balance($leftChild, $rightChild);
        $leftChildBalance = $leftChild
            ? NodeHeader::balance(
                $this->getNodeHeaderByPtr($leftChild->left),
                $this->getNodeHeaderByPtr($leftChild->right)
            ) : 0;
        $rightChildBalance = $rightChild
            ? NodeHeader::balance(
                $this->getNodeHeaderByPtr($rightChild->left),
                $this->getNodeHeaderByPtr($rightChild->right)
            ) : 0;

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

    private function getNodeHeaderByPtr(int $ptr): ?NodeHeader
    {
        if ($ptr < 1) {
            return null;
        }

        $cachedNode = $this->searchNodeInCache($ptr);
        if ($cachedNode) {
            return $cachedNode;
        }

        $ptr--;
        fseek($this->f, $ptr, SEEK_SET);
        $nodeEncoded = fread($this->f, NodeHeader::ENCODED_HEADER_SIZE);
        if ($nodeEncoded === false || strlen($nodeEncoded) < NodeHeader::ENCODED_HEADER_SIZE) {
            return null;
        }
        $node = NodeHeader::decodeHeader($nodeEncoded);
        $node->ptr = $ptr + 1;
        $this->addOrUpdateNodeHeaderCache($node);
        return $node;
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
        $node = Node::decodeFullNode($nodeEncoded, $this->valueSize);
        $node->ptr = $ptr + 1;
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
        return NodeHeader::height($this->getRootNodeHeader());
    }

    public function find(int $key): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $parentPtr = $this->rootPtr;
        $parent = $this->getNodeHeaderByPtr($parentPtr);

        while (
            $parent->key !== $key && (
                ($key <= $parent->key && $parent->left) ||
                ($key >  $parent->key && $parent->right)
            )
        ) {
            $parentPtr = $key <= $parent->key ? $parent->left : $parent->right;
            $parent = $this->getNodeHeaderByPtr($parentPtr);
        }
        if ($parent->key !== $key) {
            return null;
        }
        return $this->getNodeByPtr($parent->ptr)->value;
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
    private function _inOrder(?Node $root, array &$arr = []): array
    {
        if (!$root) {
            return $arr;
        }

        $this->_inOrder($this->getNodeByPtr($root->left), $arr);
        array_push($arr, $root);
        $this->_inOrder($this->getNodeByPtr($root->right), $arr);
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
    private function _findRange(int $fromKey, int $toKey, ?NodeHeader $root, array &$arr = []): array
    {
        if (!$root) {
            return $arr;
        }

        if ($fromKey <= $root->key) {
            $this->_findRange(
                $fromKey,
                $toKey,
                $this->getNodeHeaderByPtr($root->left),
                $arr
            );
        }

        if ($root->key >= $fromKey && $root->key <= $toKey) {
            $root = $this->getNodeByPtr($root->ptr);
            array_push($arr, $root);
        }

        if ($toKey >= $root->key) {
            $this->_findRange(
                $fromKey,
                $toKey,
                $this->getNodeHeaderByPtr($root->right),
                $arr
            );
        }
        return $arr;
    }

    private function clockWiseRot(NodeHeader $node): int
    {
        $pivot = $this->getNodeHeaderByPtr($node->right);
        $pivotLeftPtr = $pivot->left;

        $this->setNodeRightPtr($node, $pivotLeftPtr);
        $this->setNodeLeftPtr($pivot, $node->ptr);

        $this->recomputeHeight($node);
        $this->recomputeHeight($pivot);

        return $pivot->ptr;
    }

    private function antiClockWiseRot(NodeHeader $node): int
    {
        $pivot = $this->getNodeHeaderByPtr($node->left);
        $pivotRightPtr = $pivot->right;

        $this->setNodeLeftPtr($node, $pivotRightPtr);
        $this->setNodeRightPtr($pivot, $node->ptr);

        $this->recomputeHeight($node);
        $this->recomputeHeight($pivot);

        return $pivot->ptr;
    }

    private function recomputeHeight(NodeHeader $node): void
    {
        $left = $this->getNodeHeaderByPtr($node->left);
        $right = $this->getNodeHeaderByPtr($node->right);
        $this->setNodeHeight($node, 1 + max(NodeHeader::height($left), NodeHeader::height($right)));
    }

    private function setNodeRightPtr(NodeHeader $node, int $value)
    {
        $offset = $node->ptr - 1 + NodeHeader::PTR_SIZE + NodeHeader::KEY_SIZE;
        $ptrEnc = NodeHeader::encodePtr($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $ptrEnc);
        $node->right = $value;
        $this->addOrUpdateNodeHeaderCache($node);
    }

    private function setNodeLeftPtr(NodeHeader $node, int $value)
    {
        $offset = $node->ptr - 1 + 0;
        $ptrEnc = NodeHeader::encodePtr($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $ptrEnc);
        $node->left = $value;
        $this->addOrUpdateNodeHeaderCache($node);
    }

    private function setNodeHeight(NodeHeader $node, int $value)
    {
        $offset = $node->ptr - 1 + NodeHeader::PTR_SIZE * 2 + NodeHeader::KEY_SIZE;
        $heightEnc = NodeHeader::encodeHeightField($value);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $heightEnc);
        $node->height = $value;
        $this->addOrUpdateNodeHeaderCache($node);
    }

    private function setNodeValue(Node $node, string $value)
    {
        $offset = $node->ptr - 1 + NodeHeader::KEY_SIZE + NodeHeader::PTR_SIZE * 2 + NodeHeader::HEIGHT_SIZE;
        $valueEnc = Node::encodeValueField($value, $this->valueSize);
        fseek($this->f, $offset, SEEK_SET);
        fwrite($this->f, $valueEnc);
        $node->value = $value;
        return true;
    }

    private function setRootPtr(int $value)
    {
        $this->rootPtr = $value;
        $ptrEnc = NodeHeader::encodePtr($value);
        fseek($this->f, 0, SEEK_SET);
        fwrite($this->f, $ptrEnc);
    }

    private function setValueSize(int $value): void
    {
        $this->valueSize = $value;
        $sizeEnc = NodeHeader::encodePtr($value);
        fseek($this->f, NodeHeader::PTR_SIZE, SEEK_SET);
        fwrite($this->f, $sizeEnc);
    }

    private function getRootNode(): Node | null
    {
        return $this->getNodeByPtr($this->rootPtr);
    }

    private function getRootNodeHeader(): NodeHeader | null
    {
        return $this->getNodeHeaderByPtr($this->rootPtr);
    }

    private function addOrUpdateNodeHeaderCache(NodeHeader $node)
    {
        $isNewEntry = !key_exists($node->ptr, $this->headersCache);
        if ($isNewEntry && $this->cacheSize >= $this->cacheLimit) {
            return;
        }
        $this->headersCache[$node->ptr] = $node;
        if ($isNewEntry) {
            $this->cacheSize++;
        }
    }

    private function searchNodeInCache(int $ptr): NodeHeader | null
    {
        if (!key_exists($ptr, $this->headersCache)) {
            return null;
        }
        return $this->headersCache[$ptr];
    }
};
