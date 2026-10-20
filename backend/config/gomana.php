<?php

return [

    /*
    | Printed on every document header and footer (section 6 of the handover file).
    */
    'company' => [
        'name' => env('COMPANY_NAME', 'الجومانا'),
        'address' => env('COMPANY_ADDRESS'),
        'phones' => env('COMPANY_PHONES'),
        'commercial_register' => env('COMPANY_COMMERCIAL_REGISTER', '39187'),
        'tax_card' => env('COMPANY_TAX_CARD', '769/443/052'),
    ],

    /*
    | Base URL of the public tracking page, printed on documents so customers can follow their shipment.
    */
    'tracking_url' => env('TRACKING_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/track'),

    /*
    | Wording printed on the driver undertaking. This is a starting text: have the company review and replace it
    | (set UNDERTAKING_TEXT) before real use.
    */
    'undertaking_text' => env(
        'UNDERTAKING_TEXT',
        'أتعهد أنا الموقع أدناه بتسليم البضاعة المحملة على السيارة المبينة بياناتها إلى جهة التسليم المحددة خلال المدة المذكورة، '
        .'وأتحمل المسؤولية الكاملة عن أي تلف أو نقص أو فقد يحدث لها أثناء النقل حتى استلام المستلم لها، '
        .'وفي حالة التأخير عن المدة المحددة ألتزم بدفع غرامة التأخير اليومية المذكورة. وهذا إقرار مني بذلك.',
    ),

    /*
    | HTML to PDF with headless Chromium through Browsershot. The server needs Node, the `puppeteer` package
    | (npm install) and a Chromium/Chrome/Edge binary, plus an Arabic font such as Noto Naskh Arabic.
    */
    'pdf' => [
        'node_binary' => env('PDF_NODE_BINARY'),
        'npm_binary' => env('PDF_NPM_BINARY'),
        'chrome_path' => env('PDF_CHROME_PATH'),
        'no_sandbox' => (bool) env('PDF_NO_SANDBOX', false),
        'timeout' => (int) env('PDF_TIMEOUT', 60),
    ],

];
