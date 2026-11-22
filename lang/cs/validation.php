<?php

return [
    'required' => 'Vyplňte pole :attribute.',
    'present' => 'Pole :attribute musí být uvedeno.',
    'string' => 'Pole :attribute musí obsahovat text.',
    'integer' => 'Pole :attribute musí obsahovat celé číslo.',
    'boolean' => 'Pole :attribute musí mít hodnotu ano nebo ne.',
    'in' => 'Vyberte platnou hodnotu pro pole :attribute.',
    'regex' => 'Pole :attribute nemá platný formát.',
    'uuid' => 'Pole :attribute musí obsahovat platné UUID.',
    'min' => ['numeric' => 'Minimální hodnota pole :attribute je :min.', 'string' => 'Minimální délka pole :attribute je :min znaků.'],
    'max' => ['numeric' => 'Maximální hodnota pole :attribute je :max.', 'string' => 'Maximální délka pole :attribute je :max znaků.'],
    'size' => ['string' => 'Požadovaná délka pole :attribute je :size znaků.'],
    'attributes' => [
        'language' => 'jazyk', 'depth' => 'hloubka hledání', 'theme' => 'vzhled',
        'message' => 'zpráva', 'comment' => 'komentář', 'branch' => 'větev', 'title' => 'název', 'body' => 'text',
    ],
];
