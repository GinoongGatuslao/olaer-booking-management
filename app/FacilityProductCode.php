<?php

namespace App;

enum FacilityProductCode: string
{
    case CottageSmall = 'COTTAGE_SMALL';
    case CottageMedium = 'COTTAGE_MEDIUM';
    case CottageLarge = 'COTTAGE_LARGE';
    case CottageExtraLarge = 'COTTAGE_EXTRA_LARGE';
    case RoomStandard = 'ROOM_STANDARD';
    case FunctionHall1 = 'FUNCTION_HALL_1';
    case FunctionHall2 = 'FUNCTION_HALL_2';
}
