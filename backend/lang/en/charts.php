<?php

return [
    'total_by' => 'Total :y by :x',
    'average_by' => 'Average :y by :x',
    'share_by' => 'Share of :y by :x',
    'by_period' => ':y by :period',
    'distribution' => 'Distribution of :y',
    'association' => 'Association between :a and :b',
    'reasons' => [
        'line' => ':y was defined as a measure recorded over time, so a line shows how it moved from period to period.',
        'bar_sum' => ':y was defined as an amount that adds up, so the totals are compared by :x.',
        'bar_mean' => ':y was defined as a property of each record (averaged, never totalled), so the average per :x is compared.',
        'pie' => ':y adds up, so each :x holds a share of the total and a pie shows who contributes most.',
        'histogram' => ':y is a measure, so its spread from low to high is shown.',
        'table' => 'Correlation statistics are exact figures, so they are listed rather than drawn.',
        'missing' => 'These columns have missing values, so data quality is visible before reading the findings.',
    ],
    'periods' => [
        'month' => 'month',
        'week' => 'week',
        'day' => 'day',
        'quarter' => 'quarter',
        'year' => 'year',
        'period' => 'period',
    ],
];
