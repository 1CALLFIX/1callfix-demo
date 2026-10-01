<?php

namespace App\Support\Journey;

/**
 * REF 1CF-JOURNEY-001 — the customer-facing "job span" for every module.
 *
 * One declarative table: for each journey, an ordered list of steps (each bound to the
 * status value the module's own state machine already uses), plus the terminal outcomes
 * that end a journey early. Nothing here touches the database or changes any state
 * machine — it only describes how existing statuses read as a journey. Adding a module
 * (or a step such as "Out for delivery") is a new entry/row here, nothing else.
 *
 * Step shape: status => [label, customer-facing hint, optional: true if the step may be skipped].
 */
final class JourneyCatalog
{
    /** Booking hold reasons shown to customers (enum values of bookings.hold_reason). */
    public const HOLD_REASONS = [
        'awaiting_spares' => 'Waiting for spare parts',
        'awaiting_customer_approval' => 'Waiting for your approval',
        'awaiting_payment_decision' => 'Waiting for a payment decision',
        'other_customer_issue' => 'Waiting on a customer-side issue',
        'provider_unresponsive' => 'Looking into a delay',
        'payment_not_reconciled' => 'Checking a payment',
        'other_provider_issue' => 'Looking into a delay',
    ];

    /** @return array<string, array{title: string, steps: array<string, array{0:string,1:string,2?:bool}>, terminals: array<string, array{0:string,1:string,2:string}>, holdable: bool}> */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------- home services
            'service' => [
                'title' => 'Service job',
                'actor' => 'professional',
                'holdable' => true,
                'steps' => [
                    'pending' => ['Booked', 'We received your booking.'],
                    'searching_provider' => ['Finding a professional', 'Matching you with the best available professional.'],
                    'assigned' => ['Professional assigned', ':pro is assigned to your job.'],
                    'provider_en_route' => ['On the way', ':pro is heading to you.'],
                    'in_progress' => ['Work in progress', ':pro has started the job.'],
                    'completed' => ['Completed', 'Job finished. Thank you!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This booking was cancelled.', 'red'],
                    'disputed' => ['Under review', 'Our team is reviewing this booking with you.', 'amber'],
                ],
            ],

            // ---------------------------------------------------------------- parcel delivery
            'parcel' => [
                'title' => 'Parcel delivery',
                'actor' => 'rider',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Order placed', 'Your pickup request is in.'],
                    'searching_worker' => ['Finding a rider', 'Looking for a nearby delivery partner.'],
                    'assigned' => ['Rider assigned', ':pro will collect your parcel.'],
                    'worker_en_route_pickup' => ['Heading to pickup', ':pro is on the way to collect it.'],
                    'picked_up' => ['Parcel picked up', ':pro has your parcel.'],
                    'en_route_dropoff' => ['Out for delivery', 'On its way to the drop-off address.'],
                    'delivered' => ['Delivered', 'Your parcel has been delivered.'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This order was cancelled.', 'red'],
                    'disputed' => ['Under review', 'Our team is looking into this delivery.', 'amber'],
                ],
            ],

            // ---------------------------------------------------------------- taxi
            'taxi' => [
                'title' => 'Taxi ride',
                'actor' => 'driver',
                'holdable' => false,
                'steps' => [
                    'requested' => ['Ride requested', 'We are setting up your ride.'],
                    'searching_driver' => ['Finding a driver', 'Looking for a nearby driver.'],
                    'assigned' => ['Driver assigned', ':pro is confirmed.'],
                    'driver_en_route' => ['Driver arriving', ':pro is on the way to your pickup point.'],
                    'trip_started' => ['Trip started', 'You are on your way.'],
                    'trip_completed' => ['Trip completed', 'You have arrived. Thanks for riding!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This ride was cancelled.', 'red'],
                    'disputed' => ['Under review', 'Our team is reviewing this trip.', 'amber'],
                ],
            ],

            // ---------------------------------------------------------------- food delivery
            'food' => [
                'title' => 'Food delivery',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Order placed', 'Waiting for the restaurant to accept.'],
                    'accepted' => ['Order accepted', 'The restaurant confirmed your order.'],
                    'preparing' => ['Preparing your food', 'Your meal is being cooked fresh.'],
                    'ready' => ['Ready', 'Packed and ready for pickup/delivery.'],
                    // Optional until delivery tracking statuses exist in the marketplace state machine.
                    'out_for_delivery' => ['Out for delivery', 'Your order is on its way.', true],
                    'completed' => ['Delivered', 'Enjoy your meal!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This order was cancelled.', 'red'],
                ],
            ],

            // ---------------------------------------------------------------- grocery / pharmacy / ecommerce
            'retail' => [
                'title' => 'Order',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Order placed', 'Waiting for the store to accept.'],
                    'accepted' => ['Order accepted', 'The store confirmed your order.'],
                    'preparing' => ['Packing your items', 'Your items are being picked and packed.'],
                    'ready' => ['Ready', 'Packed and ready for dispatch.'],
                    'out_for_delivery' => ['Out for delivery', 'Your order is on its way.', true],
                    'completed' => ['Delivered', 'Your order has arrived.'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This order was cancelled.', 'red'],
                ],
            ],

            // ---------------------------------------------------------------- hotel / stays
            'hotel' => [
                'title' => 'Hotel booking',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Booking requested', 'Waiting for the property to confirm.'],
                    'confirmed' => ['Booking confirmed', 'Your stay is confirmed.'],
                    'checked_in' => ['Checked in', 'Welcome! Enjoy your stay.'],
                    'checked_out' => ['Checked out', 'We hope you had a great stay.'],
                    'completed' => ['Stay completed', 'All done. Thank you!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This booking was cancelled.', 'red'],
                ],
            ],

            // ---------------------------------------------------------------- vehicle / equipment rental
            'rental' => [
                'title' => 'Rental',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Reserved', 'Your reservation request is in.'],
                    'confirmed' => ['Confirmed', 'Your rental is confirmed.'],
                    'picked_up' => ['Picked up', 'You have the item.'],
                    'active' => ['In use', 'Your rental period is running.'],
                    'returned' => ['Returned', 'Item returned — final checks under way.'],
                    'completed' => ['Completed', 'Rental closed. Thank you!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This reservation was cancelled.', 'red'],
                ],
            ],

            // ---------------------------------------------------------------- property rental
            'property' => [
                'title' => 'Property stay',
                'holdable' => false,
                'steps' => [
                    'pending' => ['Requested', 'Waiting for the owner to confirm.'],
                    'confirmed' => ['Confirmed', 'Your stay is confirmed.'],
                    'checked_in' => ['Checked in', 'Welcome!'],
                    'completed' => ['Completed', 'Stay completed. Thank you!'],
                ],
                'terminals' => [
                    'cancelled' => ['Cancelled', 'This booking was cancelled.', 'red'],
                ],
            ],
        ];
    }

    public static function for(string $module): ?array
    {
        return self::all()[$module] ?? null;
    }

    /** Marketplace module slug (food, grocery, pharmacy, ecommerce, ...) -> journey key. */
    public static function journeyForMarketplaceModule(?string $module): string
    {
        return $module === 'food' ? 'food' : 'retail';
    }
}
