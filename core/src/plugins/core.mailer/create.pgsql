/* SEPARATOR */
CREATE TABLE IF NOT EXISTS ajxp_mail_queue (
 id serial PRIMARY KEY,
 recipent varchar(255) NOT NULL,
 url text NOT NULL,
 date_event integer NOT NULL,
 notification_object bytea NOT NULL,
 html integer NOT NULL
)CHARACTER SET utf8 COLLATE utf8_unicode_ci;
/* SEPARATOR */
CREATE TABLE IF NOT EXISTS ajxp_mail_sent (
 id serial PRIMARY KEY,
 recipent varchar(255) NOT NULL,
 url text NOT NULL,
 date_event integer NOT NULL,
 notification_object bytea NOT NULL,
 html integer NOT NULL
)CHARACTER SET utf8 COLLATE utf8_unicode_ci;
/* SEPARATOR */
CREATE FUNCTION ajxp_send_mail() RETURNS trigger AS $ajxp_send_mail$
    BEGIN
        INSERT INTO ajxp_mail_sent (id,recipent,url,date_event,notification_object,html)
            VALUES (OLD.id,OLD.recipent,OLD.url,OLD.date_event,OLD.notification_object,OLD.html);
        RETURN OLD;
    END;
$ajxp_send_mail$ LANGUAGE plpgsql;
/* SEPARATOR */
CREATE TRIGGER mail_queue_go_to_sent BEFORE DELETE ON ajxp_mail_queue
FOR EACH ROW EXECUTE PROCEDURE ajxp_send_mail();