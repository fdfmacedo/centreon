
use strict;
use warnings;
use File::Copy;

package centreon::centstorage::CentstoragePerfdataFile;
use centreon::common::misc;

sub new {
    my $class = shift;
    my $self  = {};
    $self->{logger} = shift;
    $self->{filehandler} = undef;
    $self->{filename} = undef;
    $self->{eof_file} = 0;
    $self->{readed} = 0;
    $self->{buffer} = [];
    bless $self, $class;
    return $self;
}

sub compute {
    my $self = shift;
    $self->{filename} = shift;
    my ($pool_pipes, $routing_services, $roundrobin_pool_current, $total_pool, $auto_duplicate, $duplicate_file) = @_;
    my $do_duplicate = 0;
    
    if (defined($auto_duplicate) && int($auto_duplicate) == 1 &&
        defined($duplicate_file) && $duplicate_file ne "") {
        if (!open(FILEDUPLICATE, ">> " . $duplicate_file)) {
            $self->{logger}->writeLogError("Can't open for write " . $duplicate_file . "_read.offset file: $!");
        } else {
            $do_duplicate = 1;
        }
    }
    
    if ($self->{filename} !~ /_read$/) {
        if (!(File::Copy::move($self->{filename}, $self->{filename} . "_read"))) {
            $self->{logger}->writeLogError("Cannot move " . $self->{filename} . " file : $!");
            return -1;
        }
        if (!open($self->{filehandler}, '+< ' . $self->{filename} . "_read")) {
            $self->{logger}->writeLogError("Cannot open " . $self->{filename} . "_read file : $!");
            return -1;
        }
    } else {
        $self->{filename} =~ s/_read$//;
        if (!open($self->{filehandler}, '+< ' . $self->{filename} . "_read")) {
            $self->{logger}->writeLogError("Cannot open " . $self->{filename} . "_read file : $!");
            return -1;
        }
    }

    # Get offset if exist
    if (-e $self->{filename} . "_read.offset") {
        if (!open(FILE, "<", $self->{filename} . "_read.offset")) {
            $self->{logger}->writeLogError("Can't read " . $self->{filename} . "_read.offset file: $!");
            return -1;
        }
        my $offset = <FILE>;
        close FILE;
        chomp $offset;
        $offset = int($offset);
        if ($offset =~ /^[0-9]+$/) {
            seek($self->{filehandler}, $offset, 1);
            $self->{readed} = $offset;
        }
        unlink($self->{filename} . "_read.offset");
    }

    my $fh = $self->{filehandler};
    while ((my ($status, $readline) = centreon::common::misc::get_line_file($fh, \@{$self->{buffer}}, \$self->{readed}))) {
        last if ($status == -1);
        if ($do_duplicate == 1) {
            print FILEDUPLICATE $readline . "\n";
        }

        $readline =~ /([0-9]+?)\t+?([^\t]+?)\t+?([^\t]+?)\t/;
        if (defined($1) && defined($2) && defined($3)) {
            if (defined($routing_services->{$2 . ";" . $3})) {
                my $tmp_fh = $pool_pipes->{$routing_services->{$2 . ";" . $3}}->{writer_two};
                print $tmp_fh "UPDATE\t$readline\n";
            } else {
                # Choose a pool
                my $pool_num = $$roundrobin_pool_current % $total_pool;
                $$roundrobin_pool_current++;
                my $tmp_fh = $pool_pipes->{$pool_num}->{writer_two};
                print $tmp_fh "UPDATE\t$readline\n";
                $routing_services->{$2 . ";" . $3} = $pool_num;
            }
        }
    }

    $self->{eof_file} = 1;
    $self->finish();
    
    if ($do_duplicate == 1) {
        close FILEDUPLICATE;
    }
    
    return 0;
}

sub finish {
    my $self = shift;

    if (defined($self->{filehandler})) {
        my $fh = $self->{filehandler};
        if ($self->{"eof_file"} == 1) {
            if (!unlink($self->{filename} . "_read")) {
                $self->{logger}->writeLogError("Cannot unlink " . $self->{filename} . "_read file : $!");
            }
            close($fh);
        } else {
            $self->{logger}->writeLogInfo("Write Offset File " . $self->{filename} . "_read.offset file");
            if (open(FILE, ">", $self->{filename} . "_read.offset")) {
                require bytes;

                my $offset = $self->{readed};
                for (my $i = scalar(@{$self->{buffer}}) - 1; $i >= 0; $i--) {
                    $offset = $offset - bytes::length(${$self->{buffer}}[$i]) - 1; # -1 = \n
                }
                # Last: Don't have \n
                $offset += 1;
                print FILE $offset . "\n";
                close FILE;
            } else {
                $self->{logger}->writeLogError("Can't write offset " . $self->{'filename'} . "_read.offset file: $!\n");
                # Slurp File
                my $rs_save = $/;
                undef $/;
                my $content_file = <$fh>;
                seek($fh, 0, 0); 
                truncate($fh, 0);
                print $fh $content_file;
                $/ = $rs_save;
            }
        }
    }
}

1;
