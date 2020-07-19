<template>
  <b-col cols="4">
    <b-card>
      <b-card-text><h5>{{name_plural}}</h5></b-card-text>
      <b-card-text v-for="(row, row_idx) in list_data" :key="row.id">
        <i class="fa fa-edit" v-if="data_select.id == row.id"></i>
        <a @click.preventDefault="handleSelect" :id="row_idx">{{row.client_name}}&nbsp;&nbsp;&nbsp;{{row.name}}</a>


      </b-card-text>
      <b-card-text v-if="list_length == 0">No records found</b-card-text>
    </b-card>
  </b-col>
</template>
<script>
export default {
  name: 'DataListComponent',
  props: [
    'list_data','list_length','data_select','name_plural',
  ],
  data: () =>{
    return{
      id:null,
      selected_record: [],
    }
  },
  methods: {
    handleSelect: function(e){
      this.$emit('handleSelect',this.list_data[e.srcElement.id])
    },

  }
}
</script>
