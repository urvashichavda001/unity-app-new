<?php

namespace App\Support;

class GreenpreneurIconCatalog
{
    public const GROUPS = [
        'bottom_navigation' => 'Bottom Navigation',
        'highlights_grid' => 'Highlights Grid',
        'plus_menu' => 'Plus Menu',
        'impact_dashboard' => 'Impact Dashboard',
        'drawer_menu' => 'Drawer Menu',
        'custom_assets' => 'Custom Assets',
    ];

    public const FLAT_MAP = [
        'home_icon' => 'bottom_nav_home',
        'my_network_icon' => 'bottom_nav_my_peers',
        'circle_icon' => 'bottom_nav_circles',
        'highlights_icon' => 'bottom_nav_highlights',
        'events_icon' => 'highlights_events',
        'referrals_icon' => 'highlights_my_referrals',
        'deals_icon' => 'plus_business_deal',
        'p2p_icon' => 'plus_p2p_meeting',
        'testimonials_icon' => 'plus_testimonial',
    ];

    public static function rows(): array
    {
        return [
            ['icon_key'=>'bottom_nav_home','icon_name'=>'Home / Feed','icon_group'=>'bottom_navigation','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.home_2','selected_icon'=>'Iconsax.home_25','feature_key'=>'community_feed','menu_key'=>'home','screen_name'=>'Bottom Navigation','usage_location'=>'Home Feed bottom tab','sort_order'=>1],
            ['icon_key'=>'bottom_nav_my_peers','icon_name'=>'My Network','icon_group'=>'bottom_navigation','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.people','selected_icon'=>'Iconsax.people5','feature_key'=>'peers','menu_key'=>'my_peers','screen_name'=>'Bottom Navigation','usage_location'=>'My Network bottom tab','sort_order'=>2],
            ['icon_key'=>'bottom_nav_impact','icon_name'=>'Green Impact','icon_group'=>'bottom_navigation','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/colaboration.png','selected_icon'=>'assets/colaboration.png','fallback_asset'=>'assets/colaboration.png','feature_key'=>'impact_score','menu_key'=>'impact','screen_name'=>'Bottom Navigation','usage_location'=>'Green Impact elevated center button','sort_order'=>3],
            ['icon_key'=>'bottom_nav_circles','icon_name'=>'My Circle','icon_group'=>'bottom_navigation','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/circlelogo.svg','selected_icon'=>'assets/circlelogo.svg','fallback_asset'=>'assets/circlelogo.svg','feature_key'=>'circles','menu_key'=>'circles','screen_name'=>'Bottom Navigation','usage_location'=>'My Circle bottom tab','sort_order'=>4],
            ['icon_key'=>'bottom_nav_highlights','icon_name'=>'Highlights','icon_group'=>'bottom_navigation','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.magic_star','selected_icon'=>'Iconsax.magic_star5','feature_key'=>'highlights','menu_key'=>'highlights','screen_name'=>'Bottom Navigation','usage_location'=>'Highlights bottom tab','sort_order'=>5],

            ['icon_key'=>'highlights_events','icon_name'=>'Events','icon_group'=>'highlights_grid','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.calendar_2','feature_key'=>'events','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>1],
            ['icon_key'=>'highlights_my_referrals','icon_name'=>'My Referrals','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.people_rounded','feature_key'=>'referrals','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>2],
            ['icon_key'=>'highlights_gratitude_script','icon_name'=>'Gratitude Script','icon_group'=>'highlights_grid','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.heart5','feature_key'=>'gratitude_score','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>3],
            ['icon_key'=>'highlights_circle_chat','icon_name'=>'Circle Chat','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.forum_rounded','feature_key'=>'chat_messaging','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>4],
            ['icon_key'=>'highlights_open_requirement','icon_name'=>'Open Requirement','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.assignment_rounded','feature_key'=>'requirements','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>5],
            ['icon_key'=>'highlights_collaborations','icon_name'=>'Collaborations','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.link_rounded','feature_key'=>'collaborations','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>6],
            ['icon_key'=>'highlights_leadership_role','icon_name'=>'Leadership Role','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.leaderboard_rounded','feature_key'=>'leadership_form','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>7],
            ['icon_key'=>'highlights_recommend_peer','icon_name'=>'Recommend a Peer','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.person_add_rounded','feature_key'=>'recommend_peer','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>8],
            ['icon_key'=>'highlights_become_mentor','icon_name'=>'Become a Mentor','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.school_rounded','feature_key'=>'become_mentor','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>9],
            ['icon_key'=>'highlights_become_speaker','icon_name'=>'Become a Speaker','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.mic_rounded','feature_key'=>'become_speaker','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>10],
            ['icon_key'=>'highlights_partner_with_us','icon_name'=>'Partner with Us','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.handshake_rounded','feature_key'=>'partner_with_us','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>11],
            ['icon_key'=>'highlights_sme_story','icon_name'=>'Share Your SME Story','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.article_rounded','feature_key'=>'sme_story','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>12],
            ['icon_key'=>'highlights_leadership_certificate','icon_name'=>'Leadership Certificate','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.workspace_premium_rounded','feature_key'=>'leadership_certificate','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>13],
            ['icon_key'=>'highlights_entrepreneur_certificate','icon_name'=>'Entrepreneur Certificate','icon_group'=>'highlights_grid','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.emoji_events_rounded','feature_key'=>'entrepreneur_certificate','screen_name'=>'HighlightsScreen','usage_location'=>'Highlights Grid','sort_order'=>14],

            ['icon_key'=>'plus_referral','icon_name'=>'Referral','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.person_add_outlined','feature_key'=>'referrals','menu_key'=>'referral','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>1],
            ['icon_key'=>'plus_business_deal','icon_name'=>'Business Deal','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.business_center_outlined','feature_key'=>'business_deals','menu_key'=>'business_deal','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>2],
            ['icon_key'=>'plus_p2p_meeting','icon_name'=>'P2P Meeting','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.people_outline_rounded','feature_key'=>'p2p_meetings','menu_key'=>'p2p_meeting','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>3],
            ['icon_key'=>'plus_testimonial','icon_name'=>'Testimonial','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.rate_review_outlined','feature_key'=>'testimonials','menu_key'=>'testimonial','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>4],
            ['icon_key'=>'plus_post_ask','icon_name'=>'Post an Ask','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.campaign_outlined','feature_key'=>'requirements','menu_key'=>'post_an_ask','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>5],
            ['icon_key'=>'plus_apply_collaboration','icon_name'=>'Apply Collaboration','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.handshake_outlined','feature_key'=>'collaborations','menu_key'=>'apply_collaboration','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>6],
            ['icon_key'=>'plus_collaboration_ask','icon_name'=>'Collaboration Ask','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.group_add_outlined','feature_key'=>'collaboration_ask','menu_key'=>'collaboration_ask','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>7],
            ['icon_key'=>'plus_register_visitor','icon_name'=>'Register Visitor','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.person_pin_circle_outlined','feature_key'=>'visitor_registration','menu_key'=>'register_visitor','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>8],
            ['icon_key'=>'plus_add_impact','icon_name'=>'Add Impact','icon_group'=>'plus_menu','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.add_task_outlined','feature_key'=>'add_impact','menu_key'=>'add_impact','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>9],
            ['icon_key'=>'plus_claim_coins','icon_name'=>'Claim Coins','icon_group'=>'plus_menu','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/coin.png','selected_icon'=>'assets/coin.png','fallback_asset'=>'assets/coin.png','feature_key'=>'claim_coins','menu_key'=>'claim_coins','screen_name'=>'Plus Menu','usage_location'=>'Central plus floating menu','sort_order'=>10],

            ['icon_key'=>'impact_my_score','icon_name'=>'My Impact Score','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.favorite_outline_rounded','feature_key'=>'impact_score','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>1],
            ['icon_key'=>'impact_my_badges','icon_name'=>'My Badges','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.emoji_events_outlined','feature_key'=>'badges','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>2],
            ['icon_key'=>'impact_my_coins','icon_name'=>'My Coins','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.monetization_on_outlined','feature_key'=>'coins_wallet','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>3],
            ['icon_key'=>'impact_collaboration_history','icon_name'=>'Collaboration History','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.history_rounded','feature_key'=>'collaborations','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>4],
            ['icon_key'=>'impact_my_referrals','icon_name'=>'My Referrals','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.share_location_outlined','feature_key'=>'referrals','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>5],
            ['icon_key'=>'impact_gratitude_score','icon_name'=>'Gratitude Score','icon_group'=>'impact_dashboard','source_type'=>'material','icon_library'=>'Material Icons','default_icon'=>'Icons.volunteer_activism_outlined','feature_key'=>'gratitude_score','screen_name'=>'Impact Dashboard','usage_location'=>'Impact Dashboard','sort_order'=>6],

            ['icon_key'=>'drawer_circulars','icon_name'=>'Circulars','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.document_text','feature_key'=>'circulars','menu_key'=>'circulars','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>1],
            ['icon_key'=>'drawer_gallery','icon_name'=>'Gallery','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.gallery','feature_key'=>'gallery','menu_key'=>'gallery','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>2],
            ['icon_key'=>'drawer_videos','icon_name'=>'Videos','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.play_circle','feature_key'=>'videos','menu_key'=>'videos','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>3],
            ['icon_key'=>'drawer_meeting_schedule','icon_name'=>'Meeting Schedule','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.calendar_1','feature_key'=>'meeting_schedule','menu_key'=>'meeting_schedule','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>4],
            ['icon_key'=>'drawer_invoices','icon_name'=>'Invoices','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.receipt_2','feature_key'=>'invoices','menu_key'=>'invoices','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>5],
            ['icon_key'=>'drawer_blocked_users','icon_name'=>'Blocked Users','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.user_minus','feature_key'=>'blocked_users','menu_key'=>'blocked_users','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>6],
            ['icon_key'=>'drawer_welcome_creative','icon_name'=>'Welcome Creative','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.magicpen','feature_key'=>'welcome_creative','menu_key'=>'welcome_creative','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>7],
            ['icon_key'=>'drawer_rate_app','icon_name'=>'Rate App','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.star','feature_key'=>'rate_app','menu_key'=>'rate_app','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>8],
            ['icon_key'=>'drawer_share_app','icon_name'=>'Share App','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.share','feature_key'=>'share_app','menu_key'=>'share_app','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>9],
            ['icon_key'=>'drawer_settings','icon_name'=>'Settings','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.setting_2','feature_key'=>'settings','menu_key'=>'settings','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>10],
            ['icon_key'=>'drawer_feedback','icon_name'=>'Help & Support','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.message_question','feature_key'=>'feedback','menu_key'=>'feedback','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>11],
            ['icon_key'=>'drawer_collaborations','icon_name'=>'Collaborations','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.link','feature_key'=>'collaborations','menu_key'=>'collaborations','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>13],
            ['icon_key'=>'drawer_logout','icon_name'=>'Logout','icon_group'=>'drawer_menu','source_type'=>'iconsax','icon_library'=>'Iconsax','default_icon'=>'Iconsax.logout','feature_key'=>'logout','menu_key'=>'logout','screen_name'=>'HomeDrawer','usage_location'=>'Side Drawer / More Menu','sort_order'=>14],

            ['icon_key'=>'custom_colaboration_png','icon_name'=>'Green Impact Center Button','icon_group'=>'custom_assets','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/colaboration.png','selected_icon'=>'assets/colaboration.png','fallback_asset'=>'assets/colaboration.png','feature_key'=>'impact_score','usage_location'=>'Green Impact center navigation button','sort_order'=>1],
            ['icon_key'=>'custom_circlelogo_svg','icon_name'=>'My Circle Navigation Icon','icon_group'=>'custom_assets','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/circlelogo.svg','selected_icon'=>'assets/circlelogo.svg','fallback_asset'=>'assets/circlelogo.svg','feature_key'=>'circles','usage_location'=>'My Circle navigation icon','sort_order'=>2],
            ['icon_key'=>'custom_coin_png','icon_name'=>'Claim Coins Icon','icon_group'=>'custom_assets','source_type'=>'custom_asset','icon_library'=>'Custom Asset','default_icon'=>'assets/coin.png','selected_icon'=>'assets/coin.png','fallback_asset'=>'assets/coin.png','feature_key'=>'claim_coins','usage_location'=>'Claim Coins action','sort_order'=>3],
        ];
    }

    public static function blankGroupedResponse(): array
    {
        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        $flat = array_fill_keys(array_keys(self::FLAT_MAP), null);
        $groups['flat'] = $flat;
        return array_merge($groups, $flat);
    }
}
