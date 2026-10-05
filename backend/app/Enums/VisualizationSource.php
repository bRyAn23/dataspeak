<?php

namespace App\Enums;

/** Dictionary enum visualization_source: who put the chart in the report. */
enum VisualizationSource: string
{
    case RECOMMENDED = 'RECOMMENDED';
    case USER = 'USER';
}
