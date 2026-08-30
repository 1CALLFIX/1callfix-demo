<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer storefront's services cart. One row per chosen service line
// (a `quantity` > 1 is fanned out to that many booking children at
// checkout). This is a browse-time convenience only — exactly like
// App\Services\CartService's own docblock says for the Marketplace product
// cart: the authoritative price and every eligibility check happen inside
// CreateBookingBundleAction at checkout, never here.
//
// `category_id` / `subcategory_id` are SNAPSHOTS taken at add time. The cart
// groups lines into "visits" by subcategory (falling back to category), and
// that grouping must stay stable even if an admin later recategorises the
// service. They are plain unsigned ints, not FKs, for the same reason —
// a recategorised or removed subcategory must not cascade-wipe a cart.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('subcategory_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            // Chosen service options, as {group_id: option_id | [option_ids]}.
            // Display/estimate only — nothing in app/ writes booking_options
            // and CreateBookingAction takes no options argument (see
            // ConfiguresServiceOptions' docblock).
            $table->json('selected_options')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('customer_note', 1000)->nullable();
            $table->decimal('unit_price_estimate', 10, 2)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_cart_items');
    }
};
