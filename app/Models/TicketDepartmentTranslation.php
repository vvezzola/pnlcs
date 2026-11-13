<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A support department's name in a language other than the default. */
class TicketDepartmentTranslation extends Model
{
    protected $fillable = ['ticket_department_id', 'language', 'name'];
}
