# Comment updates

For an existing database, apply `docker/comments.sql` before using the updated
API. It creates the comment table without changing existing articles or updates.
Fresh databases initialized from `docker/schema.sql` already include it.

Any signed-in user can comment. Only the comment author can edit the text.
The author, incident owner, moderators, and admins can delete a comment.
Comments appear alongside linked articles in the incident timeline.
