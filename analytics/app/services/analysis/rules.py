"""Every threshold used by inference, analysis, insight detection and ranking.

Named, documented and unit-tested; nothing is hidden inside handlers (FastAPI prompt Section 7).
"""

SEED = 42

# --- type inference -------------------------------------------------------------------------
TYPE_MATCH_SHARE = 0.95  # share of non-null values that must parse for numeric/date inference
CATEGORY_MAX_DISTINCT = 20  # <= this many distinct values is a category when values repeat
CATEGORY_MIN_DISTINCT_NO_REPEAT = 5  # ... or when there are at most this many values at all
CATEGORY_MAX_RATIO = 0.05  # or distinct/n <= ratio ...
CATEGORY_HARD_CAP = 100  # ... up to this many distinct values
IDENTIFIER_MIN_ROWS = 20
IDENTIFIER_UNIQUE_RATIO = 0.95

# --- quality --------------------------------------------------------------------------------
HIGH_NULL_WARN = 0.10  # null rate => quality WARNING
NULL_INSUFFICIENT = 0.90  # null rate => quality INSUFFICIENT
DUPLICATE_ROWS_WARN = 0.01
IQR_K = 1.5
OUTLIER_SHARE_WARN = 0.05

# --- descriptive -----------------------------------------------------------------------------
MIN_ROWS_DESCRIPTIVE = 1
MIN_ROWS_STD = 3
HISTOGRAM_BINS = 10
MAX_MEASURES = 8
MAX_DIMENSIONS = 5

# --- trend -----------------------------------------------------------------------------------
MIN_PERIODS_TREND = 6
MIN_PERIODS_SLOPE = 8
MAX_PERIODS = 60
GRANULARITIES = ("day", "week", "month", "quarter", "year")

# --- group comparison / tests --------------------------------------------------------------
MIN_GROUPS = 2
MAX_GROUPS = 20
MIN_GROUP_SIZE = 5
WELCH_MIN_N = 30
MANN_WHITNEY_MIN_N = 8
KRUSKAL_MIN_N = 8
ANOVA_MIN_N = 30
LEVENE_P = 0.05

# --- association -----------------------------------------------------------------------------
ASSOC_MIN_PAIRS = 30
MAX_ASSOC_PAIRS = 10
CHI_EXPECTED_MIN = 5
CHI_EXPECTED_SHARE = 0.80
MAX_CAT_PAIRS = 5

# --- insight detection ---------------------------------------------------------------------
GROWTH_RATE_MIN = 0.05  # average per-period growth magnitude
LATEST_CHANGE_MIN = 0.10
TOP_SHARE_MIN = 0.40
GROUP_DIFF_REL_MIN = 0.20
P_VALUE_MAX = 0.05
P_VALUE_STRONG = 0.01
CORR_MIN = 0.50
CRAMERS_V_MIN = 0.30
MISSING_RATE_MIN = 0.10
MAX_INSIGHTS = 10

# --- ranking weights (sum to 1) --------------------------------------------------------------
W_MAGNITUDE = 0.5
W_SUPPORT = 0.3
W_QUALITY = 0.2
QUALITY_SCORE = {"acceptable": 1.0, "warning": 0.6, "insufficient": 0.0}
