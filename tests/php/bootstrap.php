<?php

function fail($message) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fail($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_true($condition, $message) {
    if (!$condition) {
        fail($message);
    }
}

function assert_matches($pattern, $actual, $message) {
    if (!preg_match($pattern, $actual)) {
        fail($message . "\nPattern: {$pattern}\nActual: " . var_export($actual, true));
    }
}
