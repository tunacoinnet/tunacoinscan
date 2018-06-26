<?php

require_once 'w8io_pairs.php';
require_once 'w8io_blockchain_transactions.php';

class w8io_blockchain_balances
{
    private $balances;
    private $checkpoint;

    private $db;
    private $query_get_balance;

    public function __construct( $writable = true )
    {
        $this->balances = new w8io_pairs( W8IO_DB_BLOCKCHAIN_BALANCES, 'balances', $writable, 'INTEGER PRIMARY KEY|TEXT|0|0' );
        $this->checkpoint = new w8io_pairs( $this->balances->get_db(), 'checkpoint', $writable, 'INTEGER PRIMARY KEY|TEXT|0|0' );
    }

    public function get_height()
    {
        $height = $this->checkpoint->get_value( W8IO_CHECKPOINT_BLOCKCHAIN_BALANCES );
        if( !$height )
            return 0;
    
        return $height;
    }

    private function get_db()
    {
        if( !isset( $this->db ) )
            $this->db = $this->balances->get_db();

        return $this->db;
    }

    public function get_balance( $aid )
    {
        if( $this->query_get_balance == false )
        {
            $id = W8IO_CHECKPOINT_BLOCKCHAIN_BALANCES;
            $this->query_get_balance = $this->get_db()->prepare( "SELECT ( SELECT value FROM checkpoint WHERE id = $id ) AS height, ( SELECT value FROM balances WHERE id = :aid ) AS balance" );
            if( !is_object( $this->query_get_balance ) )
                return false;
        }

        if( $this->query_get_balance->execute( array( 'aid' => $aid ) ) === false )
            return false;

        $data = $this->query_get_balance->fetchAll( PDO::FETCH_ASSOC );

        if( !isset( $data[0]['balance'] ) )
            return false;

        return $data[0];
    }

    private function commit_balance( $aid, $procs )
    {
        $balance = $this->balances->get_value( $aid, 'j' );
        if( $balance === false )
            $balance = array();

        foreach( $procs as $asset => $amount )
        {
            if( isset( $balance[$asset] ) )
            {
                $amount += $balance[$asset];

                if( $amount )
                    $balance[$asset] = $amount;
                else
                    unset( $balance[$asset] );
            }
            else if( $amount )
            {
                $balance[$asset] = $amount;
            }
        }

        return $this->balances->set_pair( $aid, $balance, 'j' );
    }

    public function update_balances( $wtx )
    {
        $procs_a = array();
        $procs_b = array();

        $amount = $wtx['amount'];
        $asset = $wtx['asset'];
        $fee = $wtx['fee'];
        $afee = $wtx['afee'];
        $is_a = true;
        $is_b = false;

        switch( $wtx['type'] )
        {
            case 0:
            case 1:
            case 2:
            case 4:
            case 7:
                if( $afee == $asset )
                {
                    $procs_a[$asset] = -$amount -$fee;
                }
                else
                {
                    $procs_a[$asset] = -$amount;
                    $procs_a[$afee] = -$fee;
                }
                $procs_b[$asset] = +$amount;
                $is_b = $amount != 0;
                break;

            case 3:
            case 5:
                $procs_a[$asset] = +$amount;
                $procs_a[$afee] = -$fee;
                break;
            case 6:
                $procs_a[$asset] = -$amount;
                $procs_a[$afee] = -$fee;
                break;

            case 8:
            case 9:
            case 10:
            case 12:
                $procs_a[$afee] = -$fee;
                break;

            case 11:
                if( $wtx['b'] < 0 )
                {
                    if( $afee == $asset )
                    {
                        $procs_a[$asset] = -$amount -$fee;
                    }
                    else
                    {
                        $procs_a[$asset] = -$amount;
                        $procs_a[$afee] = -$fee;
                    }
                }
                else
                {
                    $procs_b[$asset] = +$amount;
                    $is_b = $amount != 0;
                    $is_a = false;
                }
                break;

            default:
                w8io_error( 'unknown tx type' );
        }

        if( $is_a && $this->commit_balance( $wtx['a'], $procs_a ) === false )
            w8io_trace( 'commit_balance for a' );

        if( $is_b && $this->commit_balance( $wtx['b'], $procs_b ) === false )
            w8io_trace( 'commit_balance for b' );

        return true;
    }

    public function update( $upcontext )
    {
        $transactions = $upcontext['transactions'];
        $from = $upcontext['from'];
        $to = $upcontext['to'];
        $local_height = $this->get_height();

        if( $local_height != $from )
        {
            if( $local_height > $from )
            {
                $this->balances->reset();
                $local_height = 0;

                if( false === $this->checkpoint->set_pair( W8IO_CHECKPOINT_BLOCKCHAIN_BALANCES, $local_height ) )
                    w8io_error( 'set checkpoint_transactions failed' );
            }

            $from = min( $local_height, $from );
        }

        $to = min( $to, $from + W8IO_MAX_UPDATE_BATCH );

        $wtxs = $transactions->get_from_to( $from, $to );
        if( $wtxs === false )
            w8io_error( 'unexpected get_from_to() error' );

        if( !$this->balances->begin() )
            w8io_error( 'unexpected begin() error' );

        $i = 0;
        foreach( $wtxs as $wtx )
        {
            if( $i != $wtx['block'] )
            {
                $i = $wtx['block'];
                w8io_trace( 'i', "$i (balances)" );
            }

            if( !$this->update_balances( $wtx ) )
                w8io_error( 'unexpected update_balances() error' );
        }
            
        if( false === $this->checkpoint->set_pair( W8IO_CHECKPOINT_BLOCKCHAIN_BALANCES, $to ) )
            w8io_error( 'set checkpoint_transactions failed' );

        if( !$this->balances->commit() )
            w8io_error( 'unexpected commit() error' );

        return array( 'from' => $from, 'to' => $to );
    }
}
