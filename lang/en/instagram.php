<?php

return [
    'title' => 'Instagram',
    'lede' => '«Connect with Instagram» on Deamon CMS sites runs through this Meta app. The app secret stays encrypted in Plane only; Plane handles the customer login and pushes just that account\'s token to the site over the signed agent.',
    'save' => 'Save',
    'push' => 'Push to sites again',
    'form_errors' => 'Fix the highlighted fields and try again.',
    'state_label' => 'Status',
    'state' => ['ready' => 'Ready', 'missing' => 'Missing'],
    'state_hint' => [
        'ready' => 'App id and secret are stored; CMS 1.2.76+ sites show «Connect with Instagram».',
        'missing' => 'No app id or secret. Sites can only connect with a pasted token.',
    ],
    'app' => [
        'title' => 'Meta app',
        'hint' => 'developers.facebook.com → app → Use cases → Instagram API → «API setup with Instagram login»: the Instagram app id and secret (not the Facebook app id).',
    ],
    'fields' => [
        'app_id' => 'Instagram app id',
        'app_id_hint' => 'Digits only.',
        'app_secret' => 'Instagram app secret',
        'app_secret_hint' => 'Revealed with «Show» in the Meta dashboard. Stored encrypted and never shown again.',
        'secret_saved' => 'Stored. Type a new one to replace it; leave blank to keep it.',
    ],
    'meta' => [
        'title' => 'URLs for the Meta dashboard',
        'hint' => 'Use these in the app settings.',
        'redirect_uri' => 'Valid OAuth redirect URI (Instagram login settings)',
        'data_deletion' => 'Data deletion instructions URL (App settings → Basic)',
        'privacy' => 'Privacy policy URL (App settings → Basic)',
        'terms' => 'Terms of Service URL (App settings → Basic)',
        'deauthorize' => 'Deauthorize callback URL (Instagram business login settings)',
        'deletion_request' => 'Data deletion request URL (Instagram business login settings)',
        'scope' => 'Permission: instagram_business_basic',
    ],
    'rollout' => [
        'title' => 'Sites',
        'supported' => ':count sites run CMS 1.2.76+ (Instagram module available).',
        'connected' => 'Sites with a connected Instagram account: :count',
        'none' => 'No connected sites yet.',
        'last_pushed' => 'last pushed :time',
    ],
    'flash' => [
        'saved' => 'Instagram settings saved and being pushed to sites.',
        'push_queued' => 'Instagram settings are being pushed to sites again.',
    ],
    'public' => [
        'error_title' => 'Could not start the Instagram connection',
        'error_lede' => 'The link may have expired or is invalid. Go back to Integrations → Instagram in your site admin and press «Connect with Instagram» again.',
        'error_not_configured' => 'Instagram connection is not available right now. Please try again later.',
        'deletion_title' => 'Deleting your Instagram data',
        'deletion_lede' => 'If you connected your Instagram account to a website built with Deamon CMS, the site stores only your username and your recent posts\' captions, links and images, and uses them solely to display them on that site.',
        'deletion_steps_title' => 'To delete your data',
        'deletion_step_1' => 'In the site admin, open Integrations → Instagram and press «Disconnect». The token and the imported posts and images are deleted immediately; images already used on a campaign page stay until you delete that page.',
        'deletion_step_2' => 'On Instagram, remove the app\'s access under Settings → Website permissions (Apps and websites). Instagram notifies us and we delete the token and every imported post and image, campaign images included.',
        'deletion_step_3' => 'If you cannot access the site admin, email info@codron.co with your Instagram username; your data is deleted within 7 days and you are notified.',
    ],
    'requests' => [
        'title' => 'Deletion requests from Meta',
        'hint' => 'Deauthorize and data deletion callbacks. «Manual» means no site could be reached automatically: disconnect it by hand within 7 days.',
        'none' => 'No requests yet.',
        'type' => ['deletion' => 'Data deletion', 'deauthorize' => 'Access removed'],
        'status' => ['received' => 'Received', 'completed' => 'Deleted', 'manual' => 'Manual'],
    ],
    'status' => [
        'title' => 'Instagram data deletion request',
        'code' => 'Confirmation code: :code',
        'received' => 'received :date',
        'state' => [
            'received' => 'We received your request and are deleting the data on the connected website.',
            'completed' => 'Done: the access token and every imported post and image were deleted from the connected website.',
            'manual' => 'We received your request; our team is completing the deletion by hand. It will be finished within 7 days of the request.',
        ],
        'completed_at' => 'Completed :date',
        'contact' => 'Questions: info@codron.co (include the confirmation code).',
    ],
    'legal' => [
        'updated' => 'Last updated: 27 October 2026',
        'nav' => 'Legal',
        'links' => [
            'privacy' => 'Privacy policy',
            'terms' => 'Terms of service',
            'deletion' => 'Data deletion',
        ],
    ],
    'privacy' => [
        'title' => 'Privacy policy — Instagram connection',
        'lede' => 'This policy explains which data the Deamon app receives when a business connects its Instagram professional account to a website built with Deamon CMS, what it is used for, and how it is deleted.',
        'sections' => [
            [
                'heading' => '1. Who we are',
                'paragraphs' => [
                    'Deamon CMS is a website platform developed and operated by CodRon (Kanat Medya, Türkiye). The «Deamon» Meta app and the connection service at plane.codron.co are part of this platform.',
                    'Each website belongs to a business that uses Deamon CMS (our customer). The business decides whether to connect its Instagram account; we process the data on its behalf, only to run its website. Contact: info@codron.co.',
                ],
            ],
            [
                'heading' => '2. Data we receive from Instagram',
                'paragraphs' => [
                    'We request a single permission, instagram_business_basic, and only after the account owner logs in with Instagram and approves it. With it we receive:',
                ],
                'items' => [
                    'Account details: Instagram user ID, username and account type.',
                    'The account\'s own posts: post ID, caption, media type, image or video address, thumbnail, link to the post on Instagram, publish time, and the items of album posts.',
                    'An access token that lets the website refresh this list. It is valid for 60 days and renewed automatically.',
                ],
            ],
            [
                'heading' => '3. Data we do not access',
                'paragraphs' => [
                    'We never see or store the Instagram password. We do not access direct messages, comments, followers, likes, insights, the email address, or any data about other Instagram users. The app cannot post, edit or delete anything on Instagram.',
                ],
            ],
            [
                'heading' => '4. How we use the data',
                'items' => [
                    'To show the connected account\'s own recent posts on the business\'s own website, in an Instagram section where each post links back to Instagram.',
                    'If the site owner turns on the campaign module, to turn the owner\'s posts that announce a dated campaign into a campaign page on the same website.',
                    'To show the connection status (username, last refresh) to the website\'s administrators.',
                ],
                'notes' => [
                    'The site owner can hide any post or disconnect at any time. We do not use Instagram data for advertising, profiling, analytics or training models, and we never sell or rent it.',
                ],
            ],
            [
                'heading' => '5. Where the data is stored and who can see it',
                'items' => [
                    'The access token is stored encrypted in the website\'s database on our servers. It is never shown in the admin panel.',
                    'plane.codron.co handles the Instagram login, hands the token to the website once over a signed connection, and keeps only an audit entry (username and time). It does not store the token.',
                    'Post images are copied into the website\'s media library so the page loads quickly; video files are not downloaded.',
                    'Captions and images appear publicly on the website, as they already do on the public Instagram profile.',
                ],
                'notes' => [
                    'We do not share the data with third parties, except hosting providers that run our servers on our instructions, or when the law requires it.',
                ],
            ],
            [
                'heading' => '6. How long we keep it',
                'paragraphs' => [
                    'Data is kept only while the account is connected. The website refreshes the list about once an hour; a post that was deleted on Instagram or dropped out of the list is deleted from the website at the next refresh. The only exception is a post the site owner has turned into a campaign page, which stays as that page\'s content until the owner deletes it.',
                    'When the site owner disconnects the account, the token and the imported posts and images are deleted immediately; images already used on a campaign page stay as that page\'s content until the owner deletes the page.',
                    'When access is removed on Instagram or a data deletion request reaches us (through Instagram or by email), we delete the token and every imported post and image, campaign images included: automatically within minutes, and at the latest within 7 days.',
                ],
            ],
            [
                'heading' => '7. Your choices and rights',
                'items' => [
                    'Disconnect: in the site admin, Integrations → Instagram → «Disconnect».',
                    'Remove access on Instagram: Settings → Website permissions (Apps and websites) → remove «Deamon».',
                    'Ask us: email info@codron.co with your Instagram username. We delete the data within 7 days and let you know.',
                ],
                'notes' => [
                    'Under the Turkish Personal Data Protection Law (KVKK, No. 6698) and, where it applies, the EU GDPR, you can ask us to access, correct or delete your data, or object to its processing, at info@codron.co. Step-by-step instructions are on the data deletion page.',
                ],
            ],
            [
                'heading' => '8. Security',
                'paragraphs' => [
                    'All traffic uses HTTPS. The token is stored encrypted, connection links are signed and single-use, and only the website\'s administrators can manage the connection.',
                ],
            ],
            [
                'heading' => '9. Changes',
                'paragraphs' => [
                    'If this policy changes, we update this page and the date at the top. Contact: info@codron.co.',
                ],
            ],
        ],
    ],
    'terms' => [
        'title' => 'Terms of service — Instagram connection',
        'lede' => 'These terms apply when a business connects its Instagram professional account to a website built with Deamon CMS through the Deamon app.',
        'sections' => [
            [
                'heading' => '1. The service',
                'paragraphs' => [
                    'Deamon CMS, operated by CodRon (Kanat Medya, Türkiye), lets a business show its own Instagram posts on its own website. These terms cover only the Instagram connection; the website itself is governed by the agreement between CodRon and the business.',
                ],
            ],
            [
                'heading' => '2. Who can connect',
                'paragraphs' => [
                    'You must own, or be authorised to manage, both the Instagram professional account (Business or Creator) you connect and the website you connect it to.',
                ],
            ],
            [
                'heading' => '3. Your content',
                'paragraphs' => [
                    'You keep all rights to your posts. By connecting, you allow the website to fetch your posts and images and display them on that website only. You are responsible for the content you publish on Instagram and for making sure it may appear on your website.',
                ],
            ],
            [
                'heading' => '4. Instagram and Meta rules',
                'paragraphs' => [
                    'Your use must also follow the Instagram Terms of Use and the Meta Platform Terms. We may pause the connection if Meta or the law requires it.',
                ],
            ],
            [
                'heading' => '5. Availability',
                'paragraphs' => [
                    'The connection depends on Instagram\'s service. Posts may appear with a delay, and the feed may stop if Instagram changes or withdraws access. To the extent the law allows, we are not liable for indirect damages caused by such interruptions.',
                ],
            ],
            [
                'heading' => '6. Ending the connection',
                'paragraphs' => [
                    'You can disconnect at any time in the site admin or by removing the app\'s access on Instagram. Your data is then deleted as described in the privacy policy. We may end the integration with prior notice.',
                ],
            ],
            [
                'heading' => '7. Law and contact',
                'paragraphs' => [
                    'These terms are governed by the laws of the Republic of Türkiye. Questions: info@codron.co.',
                ],
            ],
        ],
    ],
];
