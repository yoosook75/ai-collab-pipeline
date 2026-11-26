<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ops:check-disk')->hourly();
