<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'headline', 'tagline', 'order_form_template', 'hidden', 'sort_order', 'invoice_type', 'invoice_due_days'];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'invoice_due_days' => 'integer'];
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'group_id');
    }

    /**
     * Whether this group's products are billed apart from the rest: on a
     * document of their own type, or with days to pay of their own.
     */
    public function hasOwnBilling(): bool
    {
        return in_array($this->invoice_type, ['proforma', 'vat'], true) || $this->invoice_due_days !== null;
    }

    /**
     * What an invoice for this group's products is created with: its type and
     * due date, each only when the group sets it. A due date the group sets
     * is days from issue, without the end-of-month rule and the customer's
     * own terms: a group billed apart is billed on terms of its own.
     *
     * @return array{type?: string, due_date?: string}
     */
    public function invoiceOptions(?CarbonInterface $issued = null): array
    {
        $options = [];
        if (in_array($this->invoice_type, ['proforma', 'vat'], true)) {
            $options['type'] = $this->invoice_type;
        }
        if ($this->invoice_due_days !== null) {
            $options['due_date'] = Carbon::parse($issued ?? now())->startOfDay()->addDays($this->invoice_due_days)->toDateString();
        }

        return $options;
    }

    /**
     * The group with billing of its own a product belongs to, or null when
     * the product is billed as the rest of the shop.
     */
    public static function ownBillingFor(?Product $product): ?self
    {
        $group = $product?->relationLoaded('group') ? $product->group : $product?->group()->first();

        return $group instanceof self && $group->hasOwnBilling() ? $group : null;
    }
}
