<?php

namespace App\Enums;

enum AnamnesisFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Boolean = 'boolean';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Number = 'number';
    case Date = 'date';
}
