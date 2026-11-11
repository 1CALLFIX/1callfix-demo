<?php

namespace App\Livewire\Hotel;

use App\Livewire\Hotel\Concerns\InteractsWithHotel;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomAvailability;
use App\Models\HotelRoomType;
use App\Services\ActivityLogger;
use App\Support\HotelSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Hotel portal → Calendar (Hotels H1). Per kind of room, the next 30 days from a chosen date: rooms left, open or
 * closed, and the price that night. One form changes a range of dates: close (full, repairs, own guests), open again,
 * set a special price (festival, weekend) or go back to the normal price. Booked rooms are never touched: closing a
 * date only stops new bookings (HotelAvailabilityService refuses a date that is not available).
 */
class Calendar extends Component
{
    use InteractsWithHotel;

    public const DAYS_SHOWN = 30;

    public ?int $roomTypeId = null;
    public string $start = '';

    public string $from = '';
    public string $to = '';
    public string $action = 'close';
    public string $price = '';

    public string $notice = '';

    public function mount(): void
    {
        $this->roomTypeId = HotelRoomType::where('accommodation_id', $this->hotel()->id)->orderBy('id')->value('id');
        $this->start = $this->today();
        $this->from = $this->start;
        $this->to = $this->start;
    }

    public function previous(): void
    {
        $this->start = max($this->today(), Carbon::parse($this->start)->subDays(self::DAYS_SHOWN)->toDateString());
    }

    public function next(): void
    {
        $this->start = Carbon::parse($this->start)->addDays(self::DAYS_SHOWN)->toDateString();
    }

    public function apply(): void
    {
        $this->reset('notice');
        $last = Carbon::parse($this->today())->addDays((int) HotelSettings::get('hotel.booking_window_days'))->toDateString();
        $this->validate([
            'roomTypeId' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today(), 'before_or_equal:'.$last],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$last],
            'action' => ['required', 'in:close,open,price,normal'],
            'price' => [$this->action === 'price' ? 'required' : 'nullable', 'numeric', 'min:1', 'max:500000'],
        ], [
            'from.after_or_equal' => 'Choose today or a later date.',
            'to.after_or_equal' => 'The last date must be on or after the first.',
            'price.required' => 'Enter the price per night for these dates.',
        ]);

        $room = $this->ownRoom((int) $this->roomTypeId);
        $dates = collect(\Carbon\CarbonPeriod::create($this->from, $this->to))->map->toDateString();

        DB::transaction(function () use ($room, $dates) {
            foreach ($dates as $date) {
                $row = HotelRoomAvailability::where('hotel_room_type_id', $room->id)->where('date', $date)->lockForUpdate()->first()
                    ?? new HotelRoomAvailability(['hotel_room_type_id' => $room->id, 'date' => $date, 'rooms_booked' => 0, 'is_available' => true, 'reason' => 'available']);
                match ($this->action) {
                    'close' => $row->fill(['is_available' => false, 'reason' => 'blocked']),
                    'open' => $row->fill(['is_available' => true, 'reason' => 'available']),
                    'price' => $row->fill(['price_override' => round((float) $this->price, 2)]),
                    'normal' => $row->fill(['price_override' => null]),
                };
                $row->save();
            }
        });

        ActivityLogger::logModel(auth()->user(), $room, 'Room calendar changed (hotel portal)', [
            'action' => $this->action, 'from' => $this->from, 'to' => $this->to, 'price' => $this->action === 'price' ? $this->price : null,
        ]);
        $count = $dates->count();
        $this->notice = match ($this->action) {
            'close' => "Closed {$count} ".\Illuminate\Support\Str::plural('night', $count).'. Rooms already booked stay booked.',
            'open' => "Opened {$count} ".\Illuminate\Support\Str::plural('night', $count).'.',
            'price' => "Special price set for {$count} ".\Illuminate\Support\Str::plural('night', $count).'.',
            'normal' => "Normal price again for {$count} ".\Illuminate\Support\Str::plural('night', $count).'.',
        };
        $this->start = $this->from;
    }

    private function today(): string
    {
        $hotel = $this->hotel();

        return app(\App\Services\TimezoneResolver::class)->format(now(), $hotel->franchise, 'Y-m-d');
    }

    public function render()
    {
        $hotel = $this->hotel();
        $rooms = HotelRoomType::where('accommodation_id', $hotel->id)->orderBy('id')->get(['id', 'name', 'total_inventory', 'is_active']);
        $room = $this->roomTypeId ? $rooms->firstWhere('id', $this->roomTypeId) : null;

        $days = collect();
        if ($room) {
            $normal = HotelRatePlan::where('hotel_room_type_id', $room->id)->where('is_active', true)->orderBy('id')->value('nightly_rate');
            $dates = collect(\Carbon\CarbonPeriod::create($this->start, Carbon::parse($this->start)->addDays(self::DAYS_SHOWN - 1)))->map->toDateString();
            $rows = HotelRoomAvailability::where('hotel_room_type_id', $room->id)->whereIn('date', $dates)->get()
                ->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());
            $days = $dates->map(function ($date) use ($rows, $room, $normal) {
                $row = $rows->get($date);
                $booked = (int) ($row->rooms_booked ?? 0);

                return [
                    'date' => Carbon::parse($date),
                    'open' => $row === null || $row->is_available,
                    'booked' => $booked,
                    'left' => max(0, $room->total_inventory - $booked),
                    'price' => $row?->price_override !== null ? (float) $row->price_override : ($normal !== null ? (float) $normal : null),
                    'special' => $row?->price_override !== null,
                ];
            });
        }

        return view('livewire.hotel.calendar', [
            'rooms' => $rooms,
            'room' => $room,
            'days' => $days,
            'atToday' => $this->start <= $this->today(),
        ])->layout('components.layouts.hotel', ['title' => 'Calendar']);
    }
}
