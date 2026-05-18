@props(['product' => null, 'variant' => null, 'size' => 'md'])

@php
use Lunar\Facades\Pricing;

$user = auth()->user();
$isPro = false;
if ($user !== null) {
    $customer = method_exists($user, 'customers') ? $user->customers()->first() : null;
    $isPro = $customer !== null && $customer->getAttribute('sirene_status') === 'active';
}

$sizeClasses = [
    'sm' => 'text-base font-bold',
    'md' => 'text-lg font-bold',
    'lg' => 'text-2xl font-black',
    'xl' => 'text-3xl font-black',
];
$priceClass = $sizeClasses[$size] ?? $sizeClasses['md'];
$ctaSize = $size === 'lg' || $size === 'xl' ? 'md' : 'sm';

if ($isPro) {
    try {
        $target = $variant ?: $product?->variants?->first();
        $priced = $target ? Pricing::for($target)->get()->matched : null;
    } catch (\Throwable) {
        $priced = null;
    }
}
@endphp

@if ($isPro)
    @if (! empty($priced))
        <div {{ $attributes->class(['text-primary-900', $priceClass]) }}>
            {{ $priced->price->formatted() }}
            <span class="text-[10px] font-semibold text-neutral-500 uppercase tracking-wide ml-1">HT</span>
        </div>
    @else
        <span class="text-sm text-neutral-500 italic">Prix sur demande</span>
    @endif
@else
    <p class="text-sm text-neutral-500 italic">Prix réservé aux professionnels connectés</p>
@endif
