<?php

class DemoDataService
{
    public function dashboardSnapshot(string $range = '30d'): array
    {
        $ranges = [
            '30d' => [
                'metrics' => [
                    ['label' => 'Total revenue', 'value' => 2487600, 'trend' => 12.6, 'positive' => true, 'delta' => 'vs last period'],
                    ['label' => 'Operating expenses', 'value' => 1684900, 'trend' => 8.3, 'positive' => false, 'delta' => 'vs last period'],
                    ['label' => 'Operating profit', 'value' => 802700, 'trend' => 17.1, 'positive' => true, 'delta' => 'vs last period'],
                    ['label' => 'Operating margin', 'value' => 32.3, 'trend' => 4.7, 'positive' => true, 'delta' => 'vs plan'],
                    ['label' => 'Revenue growth', 'value' => 18.2, 'trend' => 2.4, 'positive' => true, 'delta' => 'year-on-year'],
                    ['label' => 'Expense growth', 'value' => 9.4, 'trend' => 1.1, 'positive' => false, 'delta' => 'year-on-year']
                ],
                'trend' => [
                    ['month' => 'Jan', 'revenue' => 2100000, 'expenses' => 1550000],
                    ['month' => 'Feb', 'revenue' => 2174000, 'expenses' => 1590000],
                    ['month' => 'Mar', 'revenue' => 2215000, 'expenses' => 1605000],
                    ['month' => 'Apr', 'revenue' => 2330000, 'expenses' => 1634000],
                    ['month' => 'May', 'revenue' => 2398000, 'expenses' => 1663000],
                    ['month' => 'Jun', 'revenue' => 2487600, 'expenses' => 1684900]
                ],
                'branches' => [
                    ['branch' => 'Johannesburg', 'revenue' => 860000, 'margin' => 34, 'status' => 'Good', 'health' => 'good'],
                    ['branch' => 'Cape Town', 'revenue' => 710000, 'margin' => 29, 'status' => 'Stable', 'health' => 'warning'],
                    ['branch' => 'Durban', 'revenue' => 640000, 'margin' => 27, 'status' => 'Watch', 'health' => 'danger']
                ],
                'operations' => [
                    ['title' => 'Inventory health', 'value' => '92%', 'meta' => 'Below target variance: 2.4%'],
                    ['title' => 'Supplier reliability', 'value' => '96%', 'meta' => 'On-time delivery above benchmark'],
                    ['title' => 'Procurement backlog', 'value' => '14', 'meta' => 'Down 6% over the last 30 days'],
                    ['title' => 'Sales conversion', 'value' => '31%', 'meta' => 'Regional uplift from pricing cycle']
                ],
                'risk' => [
                    ['title' => 'Unexpected branch expenditure', 'severity' => 'High', 'detail' => 'Durban overhead exceeded plan by 11.8%', 'dot' => 'danger'],
                    ['title' => 'Inventory anomaly', 'severity' => 'Medium', 'detail' => 'Fast-moving SKU 912 is trending 18% above forecast', 'dot' => 'warning'],
                    ['title' => 'Duplicate supplier invoice', 'severity' => 'Low', 'detail' => 'Two entries matched the same PO in June', 'dot' => 'info']
                ],
                'scenarios' => [
                    ['title' => 'Price optimisation', 'delta' => '+R420K revenue', 'detail' => 'Sensitivity model for premium SKUs'],
                    ['title' => 'Procurement redesign', 'delta' => '-R180K cost', 'detail' => 'Shift to preferred supplier set'],
                    ['title' => 'New branch entry', 'delta' => '+R260K margin', 'detail' => 'Pilot in East Rand region']
                ],
                'insights' => [
                    ['title' => 'Operating leverage improving', 'detail' => 'Profit expansion outpaced revenue growth in the last 90 days.'],
                    ['title' => 'Branch consolidation opportunity', 'detail' => 'Johannesburg and Cape Town are achieving better margin yield than Durban.'],
                    ['title' => 'Cash conversion stable', 'detail' => 'Collections are moving in line with the operating plan.']
                ]
            ],
            '90d' => [
                'metrics' => [
                    ['label' => 'Total revenue', 'value' => 6869000, 'trend' => 15.2, 'positive' => true, 'delta' => 'vs last period'],
                    ['label' => 'Operating expenses', 'value' => 4824000, 'trend' => 11.4, 'positive' => false, 'delta' => 'vs last period'],
                    ['label' => 'Operating profit', 'value' => 2045000, 'trend' => 19.7, 'positive' => true, 'delta' => 'vs last period'],
                    ['label' => 'Operating margin', 'value' => 29.8, 'trend' => 2.8, 'positive' => true, 'delta' => 'vs plan'],
                    ['label' => 'Revenue growth', 'value' => 16.5, 'trend' => 3.8, 'positive' => true, 'delta' => 'year-on-year'],
                    ['label' => 'Expense growth', 'value' => 10.7, 'trend' => 1.9, 'positive' => false, 'delta' => 'year-on-year']
                ],
                'trend' => [
                    ['month' => 'Jan', 'revenue' => 1900000, 'expenses' => 1470000],
                    ['month' => 'Feb', 'revenue' => 2020000, 'expenses' => 1510000],
                    ['month' => 'Mar', 'revenue' => 2160000, 'expenses' => 1585000],
                    ['month' => 'Apr', 'revenue' => 2230000, 'expenses' => 1600000],
                    ['month' => 'May', 'revenue' => 2300000, 'expenses' => 1656000],
                    ['month' => 'Jun', 'revenue' => 2432000, 'expenses' => 1681000],
                    ['month' => 'Jul', 'revenue' => 2572000, 'expenses' => 1725000],
                    ['month' => 'Aug', 'revenue' => 2648000, 'expenses' => 1762000],
                    ['month' => 'Sep', 'revenue' => 2725000, 'expenses' => 1806000]
                ],
                'branches' => [
                    ['branch' => 'Johannesburg', 'revenue' => 2145000, 'margin' => 33, 'status' => 'Strong', 'health' => 'good'],
                    ['branch' => 'Cape Town', 'revenue' => 1870000, 'margin' => 31, 'status' => 'Stable', 'health' => 'warning'],
                    ['branch' => 'Durban', 'revenue' => 1604000, 'margin' => 25, 'status' => 'Watch', 'health' => 'danger']
                ],
                'operations' => [
                    ['title' => 'Inventory health', 'value' => '90%', 'meta' => 'Slow-moving inventory reduced by 9%'],
                    ['title' => 'Supplier reliability', 'value' => '94%', 'meta' => 'Margin drag reduced after vendor review'],
                    ['title' => 'Procurement backlog', 'value' => '18', 'meta' => 'Improving in line with purchase plan'],
                    ['title' => 'Sales conversion', 'value' => '29%', 'meta' => 'Conversion rate remains consistent']
                ],
                'risk' => [
                    ['title' => 'Supplier performance deterioration', 'severity' => 'High', 'detail' => 'Durban supply lead time increased 16 days', 'dot' => 'danger'],
                    ['title' => 'Unexpected department spend', 'severity' => 'Medium', 'detail' => 'Marketing cost is above quarterly plan in Cape Town', 'dot' => 'warning'],
                    ['title' => 'Low-stock risk on premium SKU', 'severity' => 'Low', 'detail' => 'Lead time risk at East Hub requires review', 'dot' => 'info']
                ],
                'scenarios' => [
                    ['title' => 'Inventory expansion', 'delta' => '+R520K margin', 'detail' => 'Rebalance stock across top 25 SKUs'],
                    ['title' => 'Cost takeout', 'delta' => '-R311K expense', 'detail' => 'Reduce overhead at labor-heavy departments'],
                    ['title' => 'Branch expansion', 'delta' => '+R760K annual opportunity', 'detail' => 'Scalable model for regional coverage']
                ],
                'insights' => [
                    ['title' => 'Margin widening in core markets', 'detail' => 'The strongest branch network is improving operational leverage without sacrificing revenue.'],
                    ['title' => 'Vendor concentration risk', 'detail' => 'A single supplier accounts for 44% of premium stock purchases.'],
                    ['title' => 'Cash-flow readiness', 'detail' => 'Working capital remains acceptable after a 3-month inventory correction.']
                ]
            ],
            'ytd' => [
                'metrics' => [
                    ['label' => 'Total revenue', 'value' => 26438000, 'trend' => 21.6, 'positive' => true, 'delta' => 'YTD'],
                    ['label' => 'Operating expenses', 'value' => 17890000, 'trend' => 13.4, 'positive' => false, 'delta' => 'YTD'],
                    ['label' => 'Operating profit', 'value' => 8548000, 'trend' => 25.3, 'positive' => true, 'delta' => 'YTD'],
                    ['label' => 'Operating margin', 'value' => 32.4, 'trend' => 5.1, 'positive' => true, 'delta' => 'vs plan'],
                    ['label' => 'Revenue growth', 'value' => 27.9, 'trend' => 5.2, 'positive' => true, 'delta' => 'year-on-year'],
                    ['label' => 'Expense growth', 'value' => 12.1, 'trend' => 2.7, 'positive' => false, 'delta' => 'year-on-year']
                ],
                'trend' => [
                    ['month' => 'Jan', 'revenue' => 1865000, 'expenses' => 1440000],
                    ['month' => 'Feb', 'revenue' => 1942000, 'expenses' => 1490000],
                    ['month' => 'Mar', 'revenue' => 2105000, 'expenses' => 1546000],
                    ['month' => 'Apr', 'revenue' => 2206000, 'expenses' => 1583000],
                    ['month' => 'May', 'revenue' => 2380000, 'expenses' => 1634000],
                    ['month' => 'Jun', 'revenue' => 2464000, 'expenses' => 1667000],
                    ['month' => 'Jul', 'revenue' => 2582000, 'expenses' => 1704000],
                    ['month' => 'Aug', 'revenue' => 2689000, 'expenses' => 1740000],
                    ['month' => 'Sep', 'revenue' => 2801000, 'expenses' => 1782000],
                    ['month' => 'Oct', 'revenue' => 2897000, 'expenses' => 1825000],
                    ['month' => 'Nov', 'revenue' => 2965000, 'expenses' => 1869000],
                    ['month' => 'Dec', 'revenue' => 3042000, 'expenses' => 1928000]
                ],
                'branches' => [
                    ['branch' => 'Johannesburg', 'revenue' => 8245000, 'margin' => 35, 'status' => 'Strong', 'health' => 'good'],
                    ['branch' => 'Cape Town', 'revenue' => 7022000, 'margin' => 31, 'status' => 'Stable', 'health' => 'warning'],
                    ['branch' => 'Durban', 'revenue' => 6761000, 'margin' => 27, 'status' => 'Watch', 'health' => 'danger']
                ],
                'operations' => [
                    ['title' => 'Inventory health', 'value' => '93%', 'meta' => 'Stock turns improved 11% YTD'],
                    ['title' => 'Supplier reliability', 'value' => '95%', 'meta' => 'Delivery variance within target'],
                    ['title' => 'Procurement backlog', 'value' => '11', 'meta' => 'Improved vendor coordination'],
                    ['title' => 'Sales conversion', 'value' => '34%', 'meta' => 'Marketing spend generating lift']
                ],
                'risk' => [
                    ['title' => 'Unexpected departmental spend', 'severity' => 'High', 'detail' => 'Operations overspend in Durban is trending above plan', 'dot' => 'danger'],
                    ['title' => 'Inventory variance', 'severity' => 'Medium', 'detail' => 'Selected product lines show above-limit volatility', 'dot' => 'warning'],
                    ['title' => 'Duplicate invoice check', 'severity' => 'Low', 'detail' => 'Two similar records flagged for review', 'dot' => 'info']
                ],
                'scenarios' => [
                    ['title' => 'Geographic expansion', 'delta' => '+R950K projected profit', 'detail' => 'Expand into low-variance East market'],
                    ['title' => 'Supplier diversification', 'delta' => '-R450K procurement risk', 'detail' => 'Reduce single-source concentration'],
                    ['title' => 'Margin recovery plan', 'delta' => '+R680K annual uplift', 'detail' => 'Program for branch-level productivity']
                ],
                'insights' => [
                    ['title' => 'Performance resilience improving', 'detail' => 'Strong profit growth is being driven by product mix and lower stock loss.'],
                    ['title' => 'Branch efficiency gap remains', 'detail' => 'Johannesburg continues to outperform the other operating regions.'],
                    ['title' => 'Demand visibility healthy', 'detail' => 'Higher confidence on the forecast model is supporting procurement planning.']
                ]
            ]
        ];

        return $ranges[$range] ?? $ranges['30d'];
    }
}
