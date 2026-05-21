<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $activityTypeTitle }} - Creative</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fb; margin: 0; padding: 24px; color: #1f2937; }
        .card { max-width: 840px; margin: 0 auto; background: #fff; border-radius: 12px; border: 1px solid #dbe1ea; overflow: hidden; }
        .head { background: linear-gradient(135deg, #0a3d91, #0c6ef4); color: #fff; padding: 28px; }
        .head h1 { margin: 0 0 8px; font-size: 28px; }
        .head p { margin: 0; opacity: .95; }
        .body { padding: 28px; }
        .meta { margin-bottom: 16px; font-size: 14px; color: #4b5563; }
        .text { white-space: pre-line; line-height: 1.6; font-size: 16px; }
        .footer { border-top: 1px solid #e5e7eb; margin-top: 24px; padding-top: 16px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        <p>{{ $brand }}</p>
        <h1>{{ $activityTypeTitle }}</h1>
        <p>Professional Activity Creative</p>
    </div>
    <div class="body">
        <div class="meta">
            <div><strong>Created By:</strong> {{ $userName }}@if(!empty($userCompany)) ({{ $userCompany }})@endif</div>
            <div><strong>City:</strong> {{ $userCity ?? 'N/A' }}</div>
            <div><strong>Date:</strong> {{ $activityDate }}</div>
        </div>

        <div class="text">{{ $creativeText }}</div>

        <div class="footer">
            Powered by Peers Global Unity
        </div>
    </div>
</div>
</body>
</html>
