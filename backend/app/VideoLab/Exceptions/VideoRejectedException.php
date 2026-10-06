<?php

namespace App\VideoLab\Exceptions;

use RuntimeException;

/** File tải lên bị từ chối vĩnh viễn (sai định dạng/quá giới hạn): không thử lại. Message an toàn để lưu/hiển thị. */
class VideoRejectedException extends RuntimeException {}
