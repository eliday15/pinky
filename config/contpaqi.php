<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CONTPAQi Nominas Configuration
    |--------------------------------------------------------------------------
    |
    | Mapeo de codigos de concepto para importacion en CONTPAQi Nominas.
    | Puedes personalizar estos codigos en tu archivo .env
    |
    */

    'concept_codes' => [
        // Percepciones
        'regular_pay'   => env('CONTPAQI_CODE_SUELDO', 'P001'),
        'overtime_pay'  => env('CONTPAQI_CODE_HORAS_EXTRA', 'P002'),
        'holiday_pay'   => env('CONTPAQI_CODE_FESTIVO', 'P003'),
        'weekend_pay'   => env('CONTPAQI_CODE_FIN_SEMANA', 'P004'),
        'vacation_pay'  => env('CONTPAQI_CODE_VACACIONES', 'P005'),
        'bonuses'       => env('CONTPAQI_CODE_BONOS', 'P006'),

        // Deducciones
        'deductions'    => env('CONTPAQI_CODE_DEDUCCIONES', 'D001'),
    ],

    'export' => [
        // Incluir encabezados en exportacion
        'include_headers' => true,

        // Formato de fecha
        'date_format' => 'Y-m-d',

        // Separador decimal (punto para compatibilidad CONTPAQi)
        'decimal_separator' => '.',

        // Separador de miles (vacio para compatibilidad CONTPAQi)
        'thousands_separator' => '',

        // Precision decimal para montos
        'decimal_precision' => 2,
    ],
];
