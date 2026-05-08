<?php

namespace App\Utils;

use Carbon\Carbon;

class DateTimeHelper
{
    public function generate(
        string $startDate,
        string $endDate,
        array $morningSlots = ['start' => '09:00', 'end' => '12:00'],
        array $afternoonSlots = ['start' => '14:00', 'end' => '18:00'],
        int $slotMinutes = 60
    ): array {
        $slots = [];
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($start->lte($end)) {
            $this->addSlotsForPeriod($slots, $start->toDateString(), $morningSlots['start'], $morningSlots['end'], $slotMinutes);
            $this->addSlotsForPeriod($slots, $start->toDateString(), $afternoonSlots['start'], $afternoonSlots['end'], $slotMinutes);
            $start->addDay();
        }

        return $slots;
    }

    private function addSlotsForPeriod(array &$slots, string $date, string $periodStart, string $periodEnd, int $slotMinutes): void
    {
        $slotStart = Carbon::parse($date . ' ' . $periodStart);
        $periodEnd = Carbon::parse($date . ' ' . $periodEnd);

        while ($slotStart->copy()->addMinutes($slotMinutes)->lte($periodEnd)) {
            $slots[] = [
                'date'       => $date,
                'heure_debut'=> $slotStart->format('H:i'),
                'heure_fin'  => $slotStart->copy()->addMinutes($slotMinutes)->format('H:i'),
            ];
            $slotStart->addMinutes($slotMinutes);
        }
    }
}
