<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

class LeadObserver
{
    public function saving(Lead $lead): void
    {
        $lead->name = $lead->name.' kept';
    }
}
