<?php
require __DIR__ . '/../ftree.inc.php';

function test_append_then_find_returns_value(): void {
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'hoy estamos joya');
        assertSame('hoy estamos joya', $ft->find(25));
    });
}

function test_find_missing_key_returns_null(): void {
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'hoy estamos joya');
        assertNull($ft->find(999));
    });
}

function test_find_on_empty_tree_returns_null(): void {
    withTempFTree(function (FTree $ft) {
        assertNull($ft->find(1));
    });
}

function test_append_duplicate_key_updates_in_place_without_adding_a_node(): void {
    withTempFTree(function (FTree $ft) {
        $ft->appendOrUpdate(28, 'un dia cualquiera');
        $ft->appendOrUpdate(28, 'mi cumpleanos');
        assertSame('mi cumpleanos', $ft->find(28));
        assertSame(1, count($ft->inOrder()));
    });
}

function test_find_root_key_with_children_is_still_found(): void {
    // Regression: find() used to keep descending past an exact match
    // whenever the matching node also had a left child.
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'root');
        $ft->append(3, 'left child');
        assertSame('root', $ft->find(25));
    });
}

function test_inOrder_returns_keys_in_ascending_order(): void {
    withTempFTree(function (FTree $ft) {
        foreach ([25, 3, 6, 1, 99] as $k) {
            $ft->append($k, "v$k");
        }
        assertSame([1, 3, 6, 25, 99], array_keys($ft->inOrder()));
    });
}

function test_inOrder_on_empty_tree_returns_empty_array(): void {
    withTempFTree(function (FTree $ft) {
        assertSame([], $ft->inOrder());
    });
}

function test_findRange_includes_only_keys_within_bounds(): void {
    withTempFTree(function (FTree $ft) {
        foreach ([25, 3, 6, 28] as $k) {
            $ft->append($k, "v$k");
        }
        assertSame([3, 6], array_keys($ft->findRange(2, 9)));
    });
}

function test_findRange_is_inclusive_of_exact_bounds(): void {
    withTempFTree(function (FTree $ft) {
        foreach ([10, 20, 30] as $k) {
            $ft->append($k, "v$k");
        }
        assertSame([10, 20, 30], array_keys($ft->findRange(10, 30)));
    });
}

function test_findRange_outside_all_keys_returns_empty_array(): void {
    withTempFTree(function (FTree $ft) {
        foreach ([10, 20, 30] as $k) {
            $ft->append($k, "v$k");
        }
        assertSame([], array_keys($ft->findRange(1000, 2000)));
    });
}

function test_update_on_existing_key_changes_value_and_returns_true(): void {
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'hoy estamos joya');
        assertTrue($ft->update(25, 'mi cumpleanos'));
        assertSame('mi cumpleanos', $ft->find(25));
    });
}

function test_update_on_missing_key_returns_false_and_does_not_insert(): void {
    withTempFTree(function (FTree $ft) {
        assertSame(false, $ft->update(999, 'nope'));
        assertNull($ft->find(999));
        assertSame([], $ft->inOrder());
    });
}

function test_update_on_missing_key_in_nonempty_tree_returns_false_and_does_not_corrupt(): void {
    // Regression: update() used to walk off the end of the tree, land on a
    // bogus offset, and silently overwrite an unrelated node's value.
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'root');
        $ft->append(3, 'left child');
        assertSame(false, $ft->update(999, 'does not exist'));
        assertSame(['root', 'left child'], [$ft->find(25), $ft->find(3)]);
    });
}

function test_appendOrUpdate_inserts_when_missing_and_updates_when_present(): void {
    withTempFTree(function (FTree $ft) {
        assertTrue($ft->appendOrUpdate(28, 'un dia cualquiera'));
        assertSame('un dia cualquiera', $ft->find(28));
        assertTrue($ft->appendOrUpdate(28, 'mi cumpleanos'));
        assertSame('mi cumpleanos', $ft->find(28));
        assertSame(1, count($ft->inOrder()));
    });
}

function test_append_persists_height_on_disk(): void {
    // Regression: _append() computed each parent's new height but never
    // wrote it back, so the height field stayed 0 forever.
    withTempFTree(function (FTree $ft) {
        assertSame(0, $ft->height());
        $ft->append(25, 'root');
        assertSame(1, $ft->height());
        $ft->append(3, 'left child, still height 1 under root');
        assertSame(2, $ft->height());
        // This would deepen the left chain to height 3 in a plain BST, but
        // AVL rotates it (LR case) into a balanced height-2 tree instead.
        $ft->append(6, 'right of 3, triggers a rebalancing rotation');
        assertSame(2, $ft->height());
    });
}

function test_data_survives_reopening_the_same_file(): void {
    $path = tempnam(sys_get_temp_dir(), 'ftree_test_');
    unlink($path);
    try {
        $ft = new FTree($path);
        $ft->append(25, 'hoy estamos joya');
        $ft->append(3, 'es 3');
        unset($ft); // force __destruct to close the handle before reopening

        $reopened = new FTree($path);
        assertSame('hoy estamos joya', $reopened->find(25));
        assertSame('es 3', $reopened->find(3));
    } finally {
        @unlink($path);
    }
}

function test_values_round_trip_utf8(): void {
    withTempFTree(function (FTree $ft) {
        $text = 'café con buena señal 😀';
        $ft->append(1, $text);
        assertSame($text, $ft->find(1));
    });
}

function test_findRange_matches_brute_force_on_a_random_tree(): void {
    withTempFTree(function (FTree $ft) {
        mt_srand(42);
        $keys = [];
        for ($i = 0; $i < 150; $i++) {
            $k = mt_rand(0, 999);
            $keys[$k] = true;
            $ft->appendOrUpdate($k, "v$k");
        }
        $all = array_keys($keys);
        sort($all);

        for ($t = 0; $t < 50; $t++) {
            $a = mt_rand(0, 999);
            $b = mt_rand(0, 999);
            [$lo, $hi] = $a <= $b ? [$a, $b] : [$b, $a];
            $expected = array_values(array_filter($all, fn($k) => $k >= $lo && $k <= $hi));
            $got = array_keys($ft->findRange($lo, $hi));
            sort($got);
            assertSame($expected, $got, "range [$lo,$hi]");
        }
    });
}

function test_tree_stays_balanced_after_many_random_inserts(): void {
    withTempFTree(function (FTree $ft) {
        mt_srand(99);
        $keys = [];
        for ($i = 0; $i < 300; $i++) {
            $k = mt_rand(0, 99999);
            if (isset($keys[$k])) continue;
            $keys[$k] = true;
            $ft->append($k, "v$k");
        }

        $ref = new ReflectionClass($ft);
        $getNodeByPtr = $ref->getMethod('getNodeByPtr');
        $getNodeByPtr->setAccessible(true);
        $rootPtr = $ref->getProperty('rootPtr');
        $rootPtr->setAccessible(true);

        $violations = checkAvlInvariant($ft, $getNodeByPtr, $rootPtr->getValue($ft));
        assertSame([], $violations);

        // A real AVL tree never degenerates to a linear chain.
        assertTrue($ft->height() <= (int) ceil(1.45 * log(count($keys) + 2, 2)));
    });
}

// Recursively checks that every node's balance factor is within [-1, 1]
// and its stored height matches 1 + max(child heights). Returns a list of
// violation messages (empty means the AVL invariant holds everywhere).
function checkAvlInvariant($ft, ReflectionMethod $getNodeByPtr, int $ptr, array &$violations = []): array {
    $node = $getNodeByPtr->invoke($ft, $ptr);
    if (!$node) {
        return $violations;
    }
    checkAvlInvariant($ft, $getNodeByPtr, $node->left, $violations);
    checkAvlInvariant($ft, $getNodeByPtr, $node->right, $violations);

    $left = $getNodeByPtr->invoke($ft, $node->left);
    $right = $getNodeByPtr->invoke($ft, $node->right);
    $leftHeight = $left ? $left->height : 0;
    $rightHeight = $right ? $right->height : 0;

    if (abs($leftHeight - $rightHeight) > 1) {
        $violations[] = "key={$node->key} unbalanced (left={$leftHeight}, right={$rightHeight})";
    }
    $expectedHeight = 1 + max($leftHeight, $rightHeight);
    if ($node->height !== $expectedHeight) {
        $violations[] = "key={$node->key} stored height={$node->height}, expected={$expectedHeight}";
    }
    return $violations;
}

function test_custom_value_length_round_trips_longer_values(): void {
    $path = tempnam(sys_get_temp_dir(), 'ftree_test_');
    unlink($path);
    try {
        $ft = new FTree($path, 200); // default is 50 chars; this value needs more room
        $longValue = str_repeat('x', 150) . ' fits now';
        $ft->append(1, $longValue);
        $ft->append(2, 'short one too');
        assertSame($longValue, $ft->find(1));
        assertSame('short one too', $ft->find(2));
    } finally {
        @unlink($path);
    }
}

function test_custom_value_length_survives_reopening_without_respecifying_it(): void {
    // Regression: the value-size header field was only kept in PHP memory,
    // never written to disk, so reopening a custom-length file crashed.
    $path = tempnam(sys_get_temp_dir(), 'ftree_test_');
    unlink($path);
    try {
        $ft = new FTree($path, 200);
        $longValue = str_repeat('x', 150) . ' fits now';
        $ft->append(1, $longValue);
        unset($ft);

        $reopened = new FTree($path); // no valueLength passed - must come from the file itself
        assertSame($longValue, $reopened->find(1));
    } finally {
        @unlink($path);
    }
}

function test_rotations_do_not_leak_values_into_header_cache(): void {
    // Regression: clockWiseRot/antiClockWiseRot fetched their pivot via the
    // full node+value read instead of the header-only one, so every rotation
    // leaked a value string into what's supposed to be a header-only cache.
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'root value, should never sit in header cache');
        $ft->append(3, 'left child value, should never sit in header cache');
        $ft->append(6, 'triggers an LR rotation, should not leak either');

        $ref = new ReflectionClass($ft);
        $cacheProp = $ref->getProperty('headersCache');
        $cacheProp->setAccessible(true);

        foreach ($cacheProp->getValue($ft) as $ptr => $node) {
            assertSame(false, $node instanceof Node, "ptr $ptr leaked a full Node (with value) into the header cache");
        }
    });
}

function test_cache_size_counter_only_counts_distinct_entries(): void {
    // Regression: the counter incremented on every write, even when
    // updating an already-cached ptr, so it drastically overcounted and
    // the cap kicked in far earlier than the configured limit.
    $path = tempnam(sys_get_temp_dir(), 'ftree_test_');
    unlink($path);
    try {
        $ft = new FTree($path, 50, cacheLimit: 5);
        mt_srand(11);
        $seen = [];
        $i = 0;
        while ($i < 20) {
            $k = mt_rand(0, 99999);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $ft->append($k, "v$i");
            $i++;
        }

        $ref = new ReflectionClass($ft);
        $cacheProp = $ref->getProperty('headersCache');
        $cacheProp->setAccessible(true);
        $sizeProp = $ref->getProperty('cacheSize');
        $sizeProp->setAccessible(true);

        assertSame(5, count($cacheProp->getValue($ft)));
        assertSame(5, $sizeProp->getValue($ft));

        // The cap only throttles caching; the tree itself must stay correct.
        $i = 0;
        foreach ($seen as $k => $_) {
            assertSame("v$i", $ft->find($k));
            $i++;
        }
    } finally {
        @unlink($path);
    }
}

function test_findRange_returns_real_values_not_just_keys(): void {
    // Regression: _findRange's recursive descent fetches children via the
    // header-only cache lookup, which has no value. A match found below the
    // root used to get pushed as-is (value === null) instead of being
    // upgraded to a full fetch first.
    withTempFTree(function (FTree $ft) {
        $ft->append(25, 'value-25');
        $ft->append(3, 'value-3');
        $ft->append(6, 'value-6');
        $ft->append(28, 'value-28');

        assertSame([
            3 => 'value-3',
            6 => 'value-6',
        ], $ft->findRange(2, 9));
    });
}
