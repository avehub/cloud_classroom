{{ partial('macros/article') }}

{% if pager.total_pages > 0 %}
    <div class="article-list">
        <div class="layui-row layui-col-space20">
            {% for item in pager.items %}
                {% set article_url = url({'for':'home.article.show','id':item.id}) %}
                <div class="layui-col-md6">
                    <div class="article-card wrap">
                        <div class="info">
                            <div class="title layui-elip">
                                <a href="{{ article_url }}" title="{{ item.title }}" target="_blank">{{ item.title }}</a>
                            </div>
                            <div class="summary">{{ substr(item.summary,0,80) }}</div>
                            <div class="meta">
                                <span class="time">{{ item.create_time|time_ago }}</span>
                                <span class="view">{{ item.view_count }} 浏览</span>
                                <span class="like">{{ item.like_count }} 点赞</span>
                                <span class="comment">{{ item.comment_count }} 评论</span>
                            </div>
                        </div>
                    </div>
                </div>
            {% endfor %}
        </div>
    </div>
    {{ partial('partials/pager_ajax') }}
{% endif %}