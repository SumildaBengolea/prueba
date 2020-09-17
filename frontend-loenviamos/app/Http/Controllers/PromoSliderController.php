<?php

namespace App\Http\Controllers;

use App\PromoSlider;
use App\Slide;
use Illuminate\Http\Request;

class PromoSliderController extends Controller
{
    /**
     * @param Request $request
     */
    public function promoSlider(Request $request)
    {

        $mainSlider = PromoSlider::where('position_id', 0)->where('is_active', 1)->first();

        $otherPosition = PromoSlider::whereIn('position_id', [1, 2, 3, 4, 5, 6])->where('is_active', 1)->first();

        // if present then return that for all locations
        if ($mainSlider) {
            $mainSlides = Slide::where('promo_slider_id', $mainSlider->id)
                ->where('is_active', '1')
                ->with('promo_slider')
                ->get();

        } else {
            $mainSlides = null;
        }

        if ($otherPosition) {
            $otherSlides = Slide::where('promo_slider_id', $otherPosition->id)
                ->where('is_active', '1')
                ->with('promo_slider')
                ->get();

        } else {
            $otherSlides = null;
        }

        $response = [
            'mainSlides' => $mainSlides,
            'otherSlides' => $otherSlides,
        ];
        return response()->json($response);
    }
}
