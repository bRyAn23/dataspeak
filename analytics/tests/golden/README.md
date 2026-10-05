# Golden datasets

Small, synthetic, committed. `tiny.csv` has hand-checkable values (amount mean 30, median 30, min 10,
max 50; two regions). `retail_sales.csv` is 240 synthetic rows with Thai and Myanmar headers,
monthly growth baked in, North over-represented, and 15% missing discounts; its expected values are
cross-checked in tests with Python's `statistics` module rather than copied from the implementation.
No real customer data is ever placed here.
