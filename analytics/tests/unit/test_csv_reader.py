import pytest
from app.core.errors import AnalyticsError
from app.services.validation.csv_reader import read_csv_bytes, sniff_delimiter
from app.services.validation.validator import validate_dataset


def test_bom_and_unicode_headers_are_preserved():
    raw = "﻿ยอดขาย,ရောင်းအား,x\n1,2,3\n4,5,6\n".encode()
    parsed = read_csv_bytes(raw)
    assert parsed.had_bom and parsed.encoding == "utf-8"
    assert parsed.df.columns == ["ยอดขาย", "ရောင်းအား", "x"]
    assert validate_dataset(parsed).valid


@pytest.mark.parametrize("delim", [",", ";", "\t", "|"])
def test_delimiters_are_sniffed(delim):
    raw = f"a{delim}b{delim}c\n1{delim}2{delim}3\n4{delim}5{delim}6\n".encode()
    parsed = read_csv_bytes(raw)
    assert parsed.delimiter == delim
    assert parsed.df.columns == ["a", "b", "c"] and parsed.row_count == 2


def test_empty_file_is_invalid_dataset():
    with pytest.raises(AnalyticsError) as exc:
        read_csv_bytes(b"   \n")
    assert exc.value.code == "ANALYTICS_INVALID_DATASET"


def test_header_only_and_duplicate_columns_fail_validation():
    only_header = validate_dataset(read_csv_bytes(b"a,b,c\n"))
    assert not only_header.valid and any(e.code == "NO_DATA_ROWS" for e in only_header.errors)

    dupes = validate_dataset(read_csv_bytes(b"a,a,b\n1,2,3\n"))
    assert not dupes.valid and any(e.code == "DUPLICATE_COLUMN" and e.column == "a" for e in dupes.errors)


def test_malformed_rows_and_duplicates_are_warnings_not_errors():
    raw = b"a,b\n1,2\n1,2\n3,4,5,6\n7\n"
    result = validate_dataset(read_csv_bytes(raw))
    assert result.valid
    codes = {w.code for w in result.warnings}
    assert "DUPLICATE_ROWS" in codes


def test_null_tokens_become_nulls():
    parsed = read_csv_bytes(b"a,b\nNA,1\n,2\nnull,3\n-,4\n5,5\n")
    assert parsed.df["a"].null_count() == 4


def test_sniffer_falls_back_to_comma():
    assert sniff_delimiter("single_header\nvalue\n") == ","
