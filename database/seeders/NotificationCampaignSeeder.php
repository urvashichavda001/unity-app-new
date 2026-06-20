<?php

namespace Database\Seeders;

use App\Models\Notifications\NotificationCampaign;
use Illuminate\Database\Seeder;

class NotificationCampaignSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->campaigns() as $campaign) {
            NotificationCampaign::updateOrCreate(['code' => $campaign['code']], $campaign);
        }
    }

    private function campaigns(): array
    {
        return [
            ['code'=>'new_post_activity_circle','name'=>'New Post / Activity In Circle','category'=>'feed_social','channel'=>'push','trigger_type'=>'post_or_circle_activity','frequency'=>'real_time_or_digest','priority'=>'medium','audience_type'=>'circle_members_and_connections','title_template'=>'New post by <person>','body_template'=>'[Post Preview Content]...','tap_screen'=>'post_details','description'=>'Notifies connected peers and circle members about new posts.','daily_limit'=>5,'cooldown_hours'=>1,'is_active'=>true,'config'=>['business_value'=>'Increase feed engagement','placeholders'=>['person','post_preview_content','circle_name']]],
            ['code'=>'post_like_received','name'=>'Post Like Received','category'=>'feed_social','channel'=>'push','trigger_type'=>'post_liked','frequency'=>'immediate','priority'=>'low','audience_type'=>'post_owner','title_template'=>'<person> liked your post','body_template'=>'<person> liked your post: "[Post Preview Content]"','tap_screen'=>'post_details','description'=>'Lets post owners know when peers appreciate their post.','daily_limit'=>10,'cooldown_hours'=>0,'is_active'=>true,'config'=>['business_value'=>'Encourage social feedback loops','placeholders'=>['person','post_preview_content']]],
            ['code'=>'post_comment_received','name'=>'Post Comment Received','category'=>'feed_social','channel'=>'push','trigger_type'=>'post_commented','frequency'=>'immediate','priority'=>'medium','audience_type'=>'post_owner','title_template'=>'<person> commented on your post','body_template'=>'"[Comment Preview Content]"','tap_screen'=>'post_details','description'=>'Notifies post owners about new comments.','daily_limit'=>10,'cooldown_hours'=>0,'is_active'=>true,'config'=>['business_value'=>'Drive conversation and replies','placeholders'=>['person','comment_preview_content','post_preview_content']]],
            ['code'=>'user_mention_notification','name'=>'User Mention Notification','category'=>'feed_social','channel'=>'push','trigger_type'=>'user_mentioned','frequency'=>'immediate','priority'=>'high','audience_type'=>'mentioned_user','title_template'=>'<person> mentioned you!','body_template'=>'<person> mentioned you in a post: "[Post Preview Content]"','tap_screen'=>'post_details','description'=>'Alerts members when they are mentioned in feed activity.','daily_limit'=>10,'cooldown_hours'=>0,'is_active'=>true,'config'=>['business_value'=>'Bring mentioned users into discussions','placeholders'=>['person','post_preview_content','comment_preview_content']]],
            ['code'=>'share_post_alert','name'=>'Share Post Alert','category'=>'feed_social','channel'=>'push','trigger_type'=>'post_shared','frequency'=>'immediate','priority'=>'low','audience_type'=>'post_owner','title_template'=>'Your post was shared!','body_template'=>'<person> shared your post: "[Post Preview Content]"','tap_screen'=>'post_details','description'=>'Notifies post owners when their content is shared.','daily_limit'=>10,'cooldown_hours'=>0,'is_active'=>true,'config'=>['business_value'=>'Show content reach and encourage more posting','placeholders'=>['person','post_preview_content']]],
        ];
    }
}
