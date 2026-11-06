<?php
require_once 'ftree.inc.php';

class Day {
    public function __construct(public int $epoch, public array $records) { }
}

class Calendar {
    public function __construct(private FTree $ftree) { }

    private function mapEvent(int $epoch, string $value): Day
    {
        return new Day($epoch, json_decode($value));
    }

    /** @return array<int, Day> */
    public function index(int $fromEpoch, int $toEpoch): array
    {
        $days = [];
        foreach ($this->ftree->findRange($fromEpoch, $toEpoch) as $epoch => $value) {
            $day = $this->mapEvent($epoch, $value);
            if (!$day->records) {
                // All records for this day were removed; treat it as absent.
                continue;
            }
            $days[$epoch] = $day;
        }
        return $days;
    }

    public function find(int $epoch): ?Day
    {
        $value = $this->ftree->find($epoch);
        if ($value === null) {
            return null;
        }
        $day = $this->mapEvent($epoch, $value);
        if (!$day->records) {
            return null;
        }
        return $day;
    }

    public function add(int $epoch, string $record): Day
    {
        $previousDay = $this->find($epoch);
        $records = $previousDay ? $previousDay->records : [];
        $records[] = $record;
        $this->ftree->appendOrUpdate($epoch, json_encode($records));
        return new Day($epoch, $records);
    }

    public function update(int $epoch, int $recordIdx, string $event): ?Day
    {
        $previousDay = $this->find($epoch);
        if (!$previousDay || !isset($previousDay->records[$recordIdx])) {
            return null;
        }
        $records = $previousDay->records;
        $records[$recordIdx] = $event;
        $this->ftree->update($epoch, json_encode($records));
        return new Day($epoch, $records);
    }

    public function remove(int $epoch, int $recordIdx): ?Day
    {
        $previousDay = $this->find($epoch);
        if (!$previousDay || !isset($previousDay->records[$recordIdx])) {
            return null;
        }
        $records = $previousDay->records;
        unset($records[$recordIdx]);
        $records = array_values($records); // keep it a JSON array, not an object with gaps
        $this->ftree->update($epoch, json_encode($records));
        return new Day($epoch, $records);
    }
}
