<?php

namespace App\Enums;

enum VideoStatus: string
{
    case AwaitingUpload = 'awaiting_upload';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
