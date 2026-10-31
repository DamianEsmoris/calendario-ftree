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
        $ft->append(6, 'right of 3, deepens the left chain');
        assertSame(3, $ft->height());
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
