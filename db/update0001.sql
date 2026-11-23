CREATE TABLE take_slot (
    base TEXT NOT NULL,
    n INTEGER NOT NULL,
    page TEXT NOT NULL,
    login TEXT NOT NULL,
    created INTEGER NOT NULL,
    PRIMARY KEY (base, n),
    UNIQUE (page),
    UNIQUE (base, login)
);

CREATE TABLE take_vote (
    page TEXT NOT NULL,
    login TEXT NOT NULL,
    vote INTEGER NOT NULL,
    time INTEGER NOT NULL,
    PRIMARY KEY (page, login)
);

CREATE INDEX take_vote_page ON take_vote (page);
