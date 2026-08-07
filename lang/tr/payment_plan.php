<?php

return [

    'down_payment_note' => 'Peşinat',

    'errors' => [
        'sum_mismatch' => 'Taksit tutarları ve peşinatın toplamı, toplam tutara eşit olmalıdır.',
        'sequence_invalid' => 'Taksit sıraları 1\'den başlayarak ardışık olmalı ve taksit adediyle eşleşmelidir.',
        'not_pending' => 'Bu taksit zaten tamamen tahsil edilmiş veya iptal edilmiş.',
        'amount_exceeds_remaining' => 'Tahsilat tutarı taksidin kalan tutarını aşamaz.',
        'delete_after_collection' => 'Tahsilat yapılmış bir plan silinemez; bunun yerine iptal edin.',
    ],

];
