
<form method="POST">
    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
    
    <textarea name="reply" placeholder="Write reply..." required></textarea>
    
    <button type="submit" name="send_reply">Send Reply</button>
</form>