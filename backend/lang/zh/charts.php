<?php

return [
    'total_by' => '按 :x 划分的 :y 总计',
    'average_by' => '按 :x 划分的平均 :y',
    'share_by' => '按 :x 划分的 :y 占比',
    'by_period' => '按:period统计的 :y',
    'distribution' => ':y 的分布',
    'association' => ':a 与 :b 的关联',
    'reasons' => [
        'line' => ':y 被定义为按时间记录的指标，折线图显示各期之间的变化。',
        'bar_sum' => ':y 被定义为可累加的金额或数量，因此按 :x 比较总计。',
        'bar_mean' => ':y 被定义为每条记录的属性（取平均，从不求和），因此按 :x 比较平均值。',
        'pie' => ':y 可以累加，每个 :x 占总量的一部分，饼图显示谁贡献最多。',
        'histogram' => ':y 是一个指标，因此显示其从低到高的分布。',
        'table' => '相关性统计是精确数字，因此以表格列出而不是绘图。',
        'missing' => '这些列存在缺失值，以便在阅读结论前了解数据质量。',
    ],
    'periods' => [
        'month' => '月',
        'week' => '周',
        'day' => '日',
        'quarter' => '季度',
        'year' => '年',
        'period' => '期',
    ],
];
