Topic Tree Plugin
Topic Tree is a WordPress plugin that uses AI-assisted analysis to automatically summarize site content, group posts and pages by topic similarity, and generate high-level SEO insights. It is designed to help site owners and developers better understand, organize, and evaluate large content collections.
Features
Automatic AI-powered summaries for posts and pages
Intelligent topic grouping based on content similarity
Overall site-level summary generation
SEO insight generation for individual posts and topic groups
Token usage tracking and caching for performance optimization
Graceful fallback logic when AI services are unavailable
How It Works
The plugin analyzes published posts and pages, generates concise summaries, and clusters content into meaningful topic categories. These insights can be used to understand content coverage, identify outdated or underrepresented topics, and improve site structure and SEO strategy.
All AI responses are cached to reduce redundant requests and improve performance.
Configuration
This plugin requires an OpenAI API key to enable AI-powered features.
The API key is intentionally not included in this repository.
You may configure the key by:
Extending the plugin to load it from wp-config.php, or
Injecting it via environment variables or custom configuration logic
Without an API key, the plugin will fail gracefully and log configuration warnings.
File Overview
index.php – Plugin bootstrap and core hooks
helpers.php – Utility functions and shared logic
summarization.php – AI summarization, topic extraction, and SEO logic
admin-script.js – Admin-side interactivity
admin-style.css – Admin UI styling
Intended Use
This project was developed as a research and portfolio plugin to explore:
AI-assisted content analysis
Automated summarization
Topic modeling and clustering
SEO-oriented content insights
It can be adapted for production use with appropriate configuration and safeguards.
License
GPL2
