<?php

namespace App\Support;

final class HelpdeskIntakeContract
{
    public const CHANNEL_PATTERN = '^[a-z0-9][a-z0-9._-]*$';

    public const MAX_ATTACHMENTS = 5;

    public const MAX_CHANNEL_LENGTH = 64;

    public const MAX_ID_LENGTH = 255;

    public const MAX_IDENTITY_ASSERTION_LENGTH = 160;

    public const MAX_INTAKE_ID_LENGTH = 104;

    public const MAX_MESSAGE_LENGTH = 4000;

    public const MAX_PHONE_LENGTH = 64;
}
