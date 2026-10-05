<?php

return [
    // F3: QA/demo rows ("[QA] ..." names) are never public on production. null/unset => on in production only.
    'hide_qa_rows' => env('SEO_HIDE_QA_ROWS'),
];
