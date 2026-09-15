<?php

namespace App;

enum FacilitySchedulePolicy: string
{
    case Overnight = 'overnight';
    case DatedSlots = 'dated_slots';
    case WholeCalendarDay = 'whole_calendar_day';
}
