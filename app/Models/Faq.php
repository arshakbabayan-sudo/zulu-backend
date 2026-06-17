<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    /** Suggested categories (free-form string column — not enforced). */
    public const CATEGORIES = ['general', 'booking', 'payment', 'account', 'partners', 'visa', 'insurance'];

    protected $fillable = [
        'category',
        'question_hy', 'question_ru', 'question_en',
        'answer_hy', 'answer_ru', 'answer_en',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The question/answer in the requested language, falling back en → hy.
     *
     * @return array{id:int, category:string, question:string, answer:string}
     */
    public function localized(string $lang): array
    {
        $code = in_array($lang, ['hy', 'ru', 'en'], true) ? $lang : 'en';
        $pick = function (string $base) use ($code): string {
            $val = (string) ($this->{$base.'_'.$code} ?? '');
            if ($val !== '') {
                return $val;
            }

            return (string) ($this->{$base.'_en'} ?? $this->{$base.'_hy'} ?? '');
        };

        return [
            'id' => $this->id,
            'category' => (string) $this->category,
            'question' => $pick('question'),
            'answer' => $pick('answer'),
        ];
    }
}
