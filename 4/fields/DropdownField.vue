<template>
  <b-form-group
    label-cols-sm="4"
    label-cols-lg="3"
    :label="field.label"
    :description="field.field_description"
    >
      <b-form-select :id="field.field_key" v-model="ddowndata_assign" :options="option" @change="$emit('update:dropdown_data', ddown_data), onChange();"></b-form-select>
    </b-form-group>
</template>
<script>
import moment from 'moment'
var qs = require('qs');
export default {
  name: 'DropdownField',
  props: [
    'field','data_select','dropdown_data'
  ],
  data: () =>{
    return{
      selected_record: {},
      key: '',
      ddown_data: '',
      commission_opts:[
        {value:'12.5000', text:'12.50 %'},
        {value:'14.0000', text:'14.00 %'},
        {value:'15.0000', text:'15.00 %'},
      ],
      type_opts:[
        {value:'Influencer', text:'Influencer'},
        {value:'Podcast', text:'Podcast'},
      ],
      core_demo_opts: [
        'A18-24','A25-34','A35-44','A44+','F18-24',
        'F25-34','F35-44','F44+','M18-24','M25-34','M35-44',
        'M44+'
      ],
      client_opts: [],
      brand_opts: [],
      offer_opts: [],
      owner_opts: [],
    }
  },
  watch: {
    data_select(newVal) {
        this.selected_record = newVal
    },
  },
  methods: {
    getClientList: function() {
      this.$http.get('/client').then((r) => {
  			this.client_opts = r.data.map(function(v, i, a) {
  				return {value:v.id, text:v.name}
  			});
  		})
    },
    getBrandList: function() {
      this.$http.get('/brand').then((r) => {
  			this.brand_opts = r.data.map(function(v, i, a) {
  				return {value:v.id, text:v.name, client_id: v.client_id}
  			});
  		})
    },
    getOfferList: function() {
      this.$http.get('/offer').then((r) => {
        this.offer_opts = r.data.map(function(v, i, a) {
          return {value:v.id, text:v.name+ '  ('+v.brand_name+')', brand_id: v.brand_id}
				});
      })
		},
    getUserList: function() {
      this.$http.get('/user').then((r) => {
				this.owner_opts = r.data.map(function(v, i, a) {
          return {value:v.id, text:v.first_name, email: v.email, alias: v.alias}
        });
      })
		},
    onChange: function() {
      if(this.field.dependent == "true") {
        var dependent_role = this.field.dependent_role
        var primarykey, dependentkey

        if(dependent_role == "primary")
        {
          primarykey = this.field.field_key;
          dependentkey = this.field.dependent_field;
        }
        //Next code goes here
      }

    },
    ifDependentData: function() {
      if(this.field.dependent == "true") {
        var dependent_role = this.field.dependent_role

        if(this.field.key == "commission") {
          if (dependent_key == "client_id") {
            this.$http.get('/client').then((r) => {
        			this.ddown_data = r.data.map(function(v, i, a) {
        				return v.commission
        			});
        		})
          }
        }
      }
    }
  },
  mounted () {
    this.getClientList();
    this.getBrandList();
		this.getOfferList();
    this.getUserList();
  },
  computed: {
    data_assign: {
      get() {
        return this.data_select
      },
      set (value) {
        this.selected_record = value
      }
    },
    key_assign: {
      get() {
        return this.field.field_key
      },
      set (value) {
        this.key = value
      }
    },
    ddowndata_assign:{
      get() {
        return this.dropdown_data
      },
      set (value) {
        this.ddown_data = value;
      }
    },
    option: function(){
      var opts = this.field.list;

      if(opts == "commission_opts"){
        return this.commission_opts
      }

      if(opts == "type_opts"){
        return this.type_opts
      }

      if(opts == "core_demo_opts"){
        return this.core_demo_opts
      }

      if(opts == "client_opts"){
        return this.client_opts
      }

      if(opts == "brand_opts"){
        return this.brand_opts
      }

      if(opts == "offer_opts"){
        return this.offer_opts
      }

      if(opts == "owner_opts"){
        return this.owner_opts
      }
    },

  }

}
</script>
