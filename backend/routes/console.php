<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('gomana:mark-overdue-installments')->dailyAt('00:10');
Schedule::command('gomana:lock-express-trips')->everyFifteenMinutes();
