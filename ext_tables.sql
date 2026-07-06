CREATE TABLE tx_abilities_trace (
	ability varchar(255) DEFAULT '' NOT NULL,
	surface varchar(16) DEFAULT '' NOT NULL,
	ok smallint unsigned DEFAULT '0' NOT NULL,
	error_code varchar(32) DEFAULT '' NOT NULL,
	error text,
	input text,
	duration_ms int unsigned DEFAULT '0' NOT NULL,
	be_user int unsigned DEFAULT '0' NOT NULL,

	KEY ability (ability(191)),
	KEY crdate (crdate)
);
