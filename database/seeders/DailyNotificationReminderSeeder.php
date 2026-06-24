<?php

namespace Database\Seeders;

use App\Models\DailyNotificationReminder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DailyNotificationReminderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Truncate the table to clear out the old records completely
        DailyNotificationReminder::query()->truncate();

        $notifications = [
            [
                'feature' => 'App-Wide',
                'activity' => 'User hasn\'t opened the app today',
                'notification_title' => 'We Miss You!',
                'notification_body' => 'Your peer network has been busy today. Tap to see what you missed.',
                'action_trigger_timing' => 'Once daily, 7–8 PM, if no app open detected',
            ],
            [
                'feature' => 'Peers',
                'activity' => 'Daily peer discovery suggestion',
                'notification_title' => 'Meet Your Next Big Connection',
                'notification_body' => '{Suggested Peer Name} from {Industry} could be a great connection. Say hello!',
                'action_trigger_timing' => 'Daily, morning slot (9–10 AM)',
            ],
            [
                'feature' => 'Circles',
                'activity' => 'Trending circle highlight',
                'notification_title' => 'This Circle Is Buzzing!',
                'notification_body' => '{Circle Name} added 12 new members this week. See what\'s trending.',
                'action_trigger_timing' => 'Daily, midday slot',
            ],
            [
                'feature' => 'Leaderboard',
                'activity' => 'Daily leaderboard teaser',
                'notification_title' => 'Are You on Today\'s Leaderboard?',
                'notification_body' => 'Check today\'s Top Performers list — you might be closer than you think!',
                'action_trigger_timing' => 'Once daily, evening slot',
            ],
            [
                'feature' => 'Coins',
                'activity' => 'Reminder of unused wallet balance',
                'notification_title' => 'You\'ve Got Coins Waiting!',
                'notification_body' => 'You have {X} unused coins sitting in your wallet. Tap to explore rewards.',
                'action_trigger_timing' => 'Once daily if balance > 0 and unused for 3+ days',
            ],
            [
                'feature' => 'Referral Report',
                'activity' => 'Encouragement to refer more peers',
                'notification_title' => 'Know Someone Who\'d Love This?',
                'notification_body' => 'Refer a peer today and earn bonus coins instantly!',
                'action_trigger_timing' => 'Once every 2 days if no referral sent in 7 days',
            ],
            [
                'feature' => 'Events Management',
                'activity' => 'Highlight upcoming events nearby',
                'notification_title' => 'Don\'t Miss What\'s Happening Near You',
                'notification_body' => '{X} events are happening in your city this week. Tap to explore.',
                'action_trigger_timing' => 'Daily, morning slot',
            ],
            [
                'feature' => 'Business Deals',
                'activity' => 'Inspire users to log a business deal',
                'notification_title' => 'Closed a Deal Lately?',
                'notification_body' => 'Log your latest business win and let your network celebrate with you!',
                'action_trigger_timing' => 'Once every 3 days if no deal logged',
            ],
            [
                'feature' => 'Testimonials',
                'activity' => 'Prompt to give a testimonial',
                'notification_title' => 'Made a Great Connection? Say It!',
                'notification_body' => 'Give a quick testimonial to a peer who impressed you this week.',
                'action_trigger_timing' => 'Once every 3 days',
            ],
            [
                'feature' => 'Life Impact',
                'activity' => 'Inspire users to share their story',
                'notification_title' => 'Your Story Could Inspire Someone Today',
                'notification_body' => 'Share a Life Impact moment and motivate the community.',
                'action_trigger_timing' => 'Once weekly',
            ],
            [
                'feature' => 'Activities Summary',
                'activity' => 'Daily activity digest',
                'notification_title' => 'Your Day on Peers Global Unity',
                'notification_body' => 'Here\'s a quick look at today\'s top activities, deals, and discussions in your circles.',
                'action_trigger_timing' => 'Once daily, end of day (8–9 PM)',
            ],
            [
                'feature' => 'Find & Build Collaboration',
                'activity' => 'Highlight open collaboration opportunities',
                'notification_title' => 'New Collaboration Opportunities Await',
                'notification_body' => '{X} peers are looking to collaborate in your industry. Tap to explore.',
                'action_trigger_timing' => 'Daily, midday slot',
            ],
            [
                'feature' => 'Industries',
                'activity' => 'Industry-specific trending news/tip',
                'notification_title' => 'Today\'s Industry Insight',
                'notification_body' => 'A quick tip for {Industry} professionals: {Insight Snippet}. Tap to read more.',
                'action_trigger_timing' => 'Daily, morning slot',
            ],
            [
                'feature' => 'Wallet & Finance',
                'activity' => 'Reward redemption nudge',
                'notification_title' => 'Treat Yourself — Redeem Your Rewards!',
                'notification_body' => 'Your coins can unlock exciting rewards today. Tap to browse the store.',
                'action_trigger_timing' => 'Once every 4 days if eligible balance exists',
            ],
            [
                'feature' => 'Event Gallery',
                'activity' => 'Throwback to a past event photo',
                'notification_title' => 'Remember This Moment?',
                'notification_body' => 'Relive highlights from {Event Name}. Tap to see the gallery.',
                'action_trigger_timing' => 'Once weekly, weekend slot',
            ],
            [
                'feature' => 'Leadership',
                'activity' => 'Inspire users to apply for leadership',
                'notification_title' => 'Ready to Lead?',
                'notification_body' => 'Leadership applications are open. Step up and make your mark!',
                'action_trigger_timing' => 'Once weekly if user hasn\'t applied',
            ],
            [
                'feature' => 'Notifications & Email',
                'activity' => 'Weekly community newsletter teaser',
                'notification_title' => 'This Week in Peers Global Unity',
                'notification_body' => 'Top deals, new members, and circle highlights — all in this week\'s digest.',
                'action_trigger_timing' => 'Once weekly, Monday morning',
            ],
            [
                'feature' => 'App-Wide',
                'activity' => 'Streak/engagement reminder',
                'notification_title' => 'Keep Your Streak Alive!',
                'notification_body' => 'You\'ve been active {X} days in a row. Open the app to keep it going!',
                'action_trigger_timing' => 'Once daily if user has an active streak',
            ],
            [
                'feature' => 'Become A Leader',
                'activity' => 'Showcase a leader success story',
                'notification_title' => 'Meet This Month\'s Rising Leader',
                'notification_body' => '{Leader Name} grew their circle by 40% this month. Get inspired!',
                'action_trigger_timing' => 'Once weekly',
            ],
            [
                'feature' => 'Ads',
                'activity' => 'Daily curated offer/deal highlight',
                'notification_title' => 'Today\'s Top Pick for You',
                'notification_body' => 'A handpicked offer from {Advertiser Name} just for you. Tap to grab it.',
                'action_trigger_timing' => 'Once daily, afternoon slot',
            ],
            [
                'feature' => 'Circle Categories',
                'activity' => 'Explore new category prompt',
                'notification_title' => 'Discover Something New Today',
                'notification_body' => 'Explore the {Category Name} category — new peers and opportunities await.',
                'action_trigger_timing' => 'Once every 2 days',
            ],
            [
                'feature' => 'Impact Cycles',
                'activity' => 'Cycle progress reminder',
                'notification_title' => 'Your Impact Cycle Is Underway!',
                'notification_body' => 'You\'re {X}% through this Impact Cycle. Keep contributing to climb higher.',
                'action_trigger_timing' => 'Once every 2 days during active cycle',
            ],
            [
                'feature' => 'App-Wide',
                'activity' => 'Re-engagement after prolonged inactivity',
                'notification_title' => 'We\'ve Got So Much to Show You!',
                'notification_body' => 'It\'s been a while! New peers, deals, and events are waiting for you.',
                'action_trigger_timing' => 'Once after 5+ days of inactivity',
            ],
            [
                'feature' => 'Recommend A Peer',
                'activity' => 'Prompt to recommend someone',
                'notification_title' => 'Know a Great Peer to Recommend?',
                'notification_body' => 'Help grow the community — recommend someone today and earn rewards.',
                'action_trigger_timing' => 'Once every 5 days',
            ],
        ];

        foreach ($notifications as $notification) {
            DailyNotificationReminder::query()->create([
                'id' => Str::uuid()->toString(),
                'feature' => $notification['feature'],
                'activity' => $notification['activity'],
                'notification_title' => $notification['notification_title'],
                'notification_body' => $notification['notification_body'],
                'action_trigger_timing' => $notification['action_trigger_timing'],
            ]);
        }
    }
}
