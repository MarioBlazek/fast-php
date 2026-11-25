---
description: Make a PHP app faster with fast-php — profile it, port its hot function to Rust, verify, enable, benchmark
argument-hint: "[-- php script.php args | --web requests.http -- php -S localhost:8000 -t public]"
---

Speed up this PHP project with fast-php, using the `fast-php` skill. You write the Rust port yourself in this session.

The workload to profile: $ARGUMENTS

If no workload was given, find out how the app runs: a CLI entry point (`bin/`, `artisan` commands, scripts in `composer.json`), or for a web app a front controller under `public/` and representative requests to put in a `requests.http`. Ask the user only if you can't tell.
